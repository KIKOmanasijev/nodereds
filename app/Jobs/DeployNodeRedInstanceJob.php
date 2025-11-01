<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\Domain;
use App\Models\NodeRedInstance;
use App\Services\DNS\CloudflareDns;
use App\Services\Provisioning\NodeRedDeployer;
use App\Services\Scheduling\CapacityPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DeployNodeRedInstanceJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes for deployments

    public function __construct(
        public readonly int $instanceId,
        public readonly ?int $userId = null
    ) {
    }

    public function handle(): void
    {
        $instance = NodeRedInstance::findOrFail($this->instanceId);
        $instance->load(['server', 'plan']);

        // Allow retrying if status is error, pending, or deploying
        if (!in_array($instance->status, ['pending', 'deploying', 'error'])) {
            Log::warning('Instance is not in deployable state', [
                'instance_id' => $instance->id,
                'status' => $instance->status,
            ]);
            return;
        }

        // Create deployment record only if this is a new attempt (not a retry)
        $isRetry = $instance->status === 'error' || $instance->status === 'deploying';
        $deployment = null;

        if (!$isRetry || $this->attempts() === 1) {
            // Create a new deployment record for new deployments or first retry attempt
            $deployment = Deployment::create([
                'node_red_instance_id' => $instance->id,
                'created_by' => $this->userId,
                'state' => 'deploying',
                'started_at' => now(),
            ]);
        } else {
            // For retries, find the latest deployment and update it
            $deployment = Deployment::where('node_red_instance_id', $instance->id)
                ->latest()
                ->first();
            
            if ($deployment) {
                $deployment->update([
                    'state' => 'deploying',
                    'started_at' => now(),
                    'completed_at' => null,
                    'reason' => null,
                ]);
            } else {
                // Create new deployment if none exists
                $deployment = Deployment::create([
                    'node_red_instance_id' => $instance->id,
                    'created_by' => $this->userId,
                    'state' => 'deploying',
                    'started_at' => now(),
                ]);
            }
        }

        try {
            $instance->update(['status' => 'deploying']);

            // Ensure server is ready
            if (!$instance->server) {
                $capacityPlanner = app(CapacityPlanner::class);
                $server = $capacityPlanner->findOrCreateServer($instance->plan);
                $instance->update(['server_id' => $server->id]);
                $instance->refresh();
            } else {
                // Server is already assigned (admin override), verify it exists and is active
                $server = $instance->server;
                if (!$server) {
                    throw new \RuntimeException('Assigned server not found');
                }
            }

            // Wait for server to be active (if provisioning)
            if ($instance->server->status === 'provisioning') {
                // Check how long the server has been provisioning (max 15 minutes)
                $provisioningSince = $instance->server->provisioned_at ?? $instance->server->created_at;
                $waitTime = now()->diffInSeconds($provisioningSince);
                
                // Minimum wait time: 1 minute after server creation for SSH to be ready
                $minWaitTime = 60;
                if ($waitTime < $minWaitTime) {
                    $remainingWait = $minWaitTime - $waitTime;
                    Log::info('Server is too new, waiting for minimum boot time', [
                        'instance_id' => $instance->id,
                        'server_id' => $instance->server->id,
                        'wait_time_seconds' => $waitTime,
                        'remaining_wait_seconds' => $remainingWait,
                    ]);
                    $this->release($remainingWait);
                    return;
                }
                
                if ($waitTime > 600) { // 10 minutes max wait
                    throw new \RuntimeException('Server provisioning timeout after ' . $waitTime . ' seconds. Server status: ' . $instance->server->status);
                }

                // Release job back to queue with delay to wait for provisioning
                Log::info('Server is still provisioning, releasing job to retry later', [
                    'instance_id' => $instance->id,
                    'server_id' => $instance->server->id,
                    'wait_time_seconds' => $waitTime,
                ]);

                // Release job back to queue with a longer delay (60 seconds) for provisioning servers
                $this->release(60);
                return;
            }

            // Ensure server is active
            if ($instance->server->status !== 'active') {
                // Try to sync status from Hetzner first - might be a status mismatch
                Log::info('Server status is not active, attempting to sync from Hetzner', [
                    'instance_id' => $instance->id,
                    'server_id' => $instance->server->id,
                    'current_status' => $instance->server->status,
                ]);
                
                $synced = $instance->server->syncStatusFromHetzner();
                $instance->server->refresh();
                
                if ($synced && $instance->server->status === 'active') {
                    Log::info('Server status synced successfully, status is now active', [
                        'instance_id' => $instance->id,
                        'server_id' => $instance->server->id,
                    ]);
                } else {
                    // If sync failed or still not active, try SSH connectivity as last resort
                    // Server might be running but Hetzner API might have issues
                    if ($instance->server->public_ip) {
                        try {
                            $ssh = new \App\Services\SSH\Ssh($instance->server->public_ip);
                            $sshConnected = $ssh->testConnection();
                            
                            if ($sshConnected) {
                                Log::warning('Server status is not active but SSH connection works, updating status to active', [
                                    'instance_id' => $instance->id,
                                    'server_id' => $instance->server->id,
                                    'current_status' => $instance->server->status,
                                ]);
                                // Server is actually running, update status manually
                                $instance->server->update(['status' => 'active']);
                                $instance->server->refresh();
                            } else {
                                throw new \RuntimeException('Server is not active. Status: ' . $instance->server->status . '. SSH connection also failed. Please check the server status in Hetzner Cloud.');
                            }
                        } catch (\Exception $e) {
                            Log::error('Failed to test SSH connectivity', [
                                'instance_id' => $instance->id,
                                'server_id' => $instance->server->id,
                                'error' => $e->getMessage(),
                            ]);
                            throw new \RuntimeException('Server is not active. Status: ' . $instance->server->status . '. Please check the server status in Hetzner Cloud.');
                        }
                    } else {
                        throw new \RuntimeException('Server is not active. Status: ' . $instance->server->status . '. Server has no public IP. Please check the server status in Hetzner Cloud.');
                    }
                }
            }

            // Generate credential secret if not set
            if (!$instance->credential_secret) {
                $instance->update(['credential_secret' => Str::random(32)]);
            }

            // Deploy Node-RED
            $deployer = new NodeRedDeployer($instance->server);
            $deploySuccess = $deployer->deploy($instance);

            if (!$deploySuccess) {
                throw new \RuntimeException('Node-RED deployment failed');
            }

            // Create DNS record (don't fail deployment if DNS fails)
            try {
                Log::info('Creating DNS record for instance', [
                    'instance_id' => $instance->id,
                    'subdomain' => $instance->subdomain,
                    'fqdn' => $instance->fqdn,
                    'server_ip' => $instance->server->public_ip,
                ]);

                $dns = new CloudflareDns();
                $dnsRecord = $dns->ensureARecord($instance->subdomain, $instance->server->public_ip);

                Log::info('DNS record created successfully', [
                    'instance_id' => $instance->id,
                    'subdomain' => $instance->subdomain,
                    'dns_record_id' => $dnsRecord['id'] ?? 'unknown',
                ]);

                // Create domain record
                Domain::updateOrCreate(
                    ['node_red_instance_id' => $instance->id],
                    [
                        'hostname' => $instance->subdomain,
                        'fqdn' => $instance->fqdn,
                        'provider' => 'cloudflare',
                        'provider_record_id' => (string) ($dnsRecord['id'] ?? ''),
                        'ssl_status' => 'pending',
                    ]
                );
            } catch (\Exception $dnsException) {
                Log::error('DNS record creation failed (non-fatal)', [
                    'instance_id' => $instance->id,
                    'subdomain' => $instance->subdomain,
                    'fqdn' => $instance->fqdn,
                    'error' => $dnsException->getMessage(),
                    'trace' => $dnsException->getTraceAsString(),
                ]);
                // Don't throw - deployment succeeded, DNS can be created later
                // Dispatch a separate job to retry DNS creation
                EnsureDnsRecordJob::dispatch($instance->id)->delay(now()->addMinutes(1));
            }

            // Update capacity
            $capacityPlanner = app(CapacityPlanner::class);
            $capacityPlanner->updateServerCapacity(
                $instance->server,
                $instance->memory_mb,
                $instance->storage_gb
            );

            // Update instance status
            $instance->update([
                'status' => 'active',
                'deployed_at' => now(),
            ]);

            $deployment->update([
                'state' => 'success',
                'completed_at' => now(),
                'logs' => $deployer->getLogs($instance),
            ]);
        } catch (\Exception $e) {
            $attempts = $this->attempts();
            $maxAttempts = $this->tries;

            Log::error('Node-RED deployment failed', [
                'instance_id' => $instance->id,
                'attempt' => $attempts,
                'max_attempts' => $maxAttempts,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update deployment with failure info
            $deployment->update([
                'state' => 'failed',
                'reason' => $e->getMessage() . " (Attempt {$attempts}/{$maxAttempts})",
                'completed_at' => now(),
                'metadata' => array_merge($deployment->metadata ?? [], [
                    'attempt' => $attempts,
                    'max_attempts' => $maxAttempts,
                ]),
            ]);

            // If we've exhausted retries, mark instance as error
            if ($attempts >= $maxAttempts) {
                $instance->update(['status' => 'error']);
            } else {
                // Keep instance in deploying state if we'll retry
                $instance->update(['status' => 'deploying']);
            }

            // Re-throw to trigger Laravel's retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $instance = NodeRedInstance::find($this->instanceId);
        if ($instance) {
            $instance->update(['status' => 'error']);
            Log::error('Node-RED deployment job failed permanently', [
                'instance_id' => $instance->id,
                'attempts' => $this->attempts(),
                'error' => $exception?->getMessage(),
            ]);
        }
    }
}
