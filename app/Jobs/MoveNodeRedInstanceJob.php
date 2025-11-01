<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\NodeRedInstance;
use App\Models\Server;
use App\Services\DNS\CloudflareDns;
use App\Services\Provisioning\NodeRedDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class MoveNodeRedInstanceJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 600; // 10 minutes for full migration

    public function __construct(
        public readonly int $instanceId,
        public readonly int $targetServerId,
        public readonly ?int $userId = null
    ) {
    }

    public function handle(): void
    {
        $instance = NodeRedInstance::findOrFail($this->instanceId);
        $instance->load(['server', 'plan']);
        
        $oldServer = $instance->server;
        $targetServer = Server::findOrFail($this->targetServerId);

        if (!$oldServer) {
            throw new \RuntimeException('Instance has no current server assigned.');
        }

        if ($oldServer->id === $targetServer->id) {
            throw new \RuntimeException('Instance is already on the target server.');
        }

        // Check if target server is active
        if ($targetServer->status !== 'active') {
            throw new \RuntimeException("Target server '{$targetServer->name}' is not active. Status: {$targetServer->status}");
        }

        // Check if target server has enough capacity
        $availableMemory = $targetServer->available_memory_mb;
        $availableDisk = $targetServer->available_disk_gb;

        if ($availableMemory < $instance->memory_mb) {
            throw new \RuntimeException(
                "Target server '{$targetServer->name}' does not have enough memory. " .
                "Required: {$instance->memory_mb} MB, Available: {$availableMemory} MB"
            );
        }

        if ($availableDisk < $instance->storage_gb) {
            throw new \RuntimeException(
                "Target server '{$targetServer->name}' does not have enough storage. " .
                "Required: {$instance->storage_gb} GB, Available: {$availableDisk} GB"
            );
        }

        Log::info('Starting instance migration', [
            'instance_id' => $instance->id,
            'old_server_id' => $oldServer->id,
            'old_server_name' => $oldServer->name,
            'target_server_id' => $targetServer->id,
            'target_server_name' => $targetServer->name,
        ]);

        // Update instance status
        $instance->update(['status' => 'deploying']);

        try {
            // Stop instance on old server
            if ($oldServer) {
                try {
                    $oldDeployer = new NodeRedDeployer($oldServer);
                    $oldDeployer->stop($instance);
                    
                    Log::info('Instance stopped on old server', [
                        'instance_id' => $instance->id,
                        'old_server_id' => $oldServer->id,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to stop instance on old server (continuing anyway)', [
                        'instance_id' => $instance->id,
                        'old_server_id' => $oldServer->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Update instance server assignment
            $instance->update(['server_id' => $targetServer->id]);
            $instance->refresh();

            // Ensure Traefik is running on target server
            $traefikPath = config('provisioning.docker.traefik_path', '/opt/traefik');
            $traefikCheck = (new \App\Services\SSH\Ssh($targetServer->public_ip))->execute("cd {$traefikPath} && docker compose ps -q traefik", false);
            $traefikRunning = $traefikCheck->isSuccess() && !empty(trim($traefikCheck->getOutput()));

            if (!$traefikRunning) {
                Log::info('Traefik not running on target server, bootstrapping', [
                    'target_server_id' => $targetServer->id,
                ]);

                $bootstrapper = new \App\Services\Provisioning\TraefikBootstrapper($targetServer);
                $bootstrapSuccess = $bootstrapper->bootstrap();

                if (!$bootstrapSuccess) {
                    throw new \RuntimeException('Failed to bootstrap Traefik on target server.');
                }

                sleep(5); // Wait for Traefik to start
            }

            // Deploy instance on new server
            $newDeployer = new NodeRedDeployer($targetServer);
            $deploySuccess = $newDeployer->deploy($instance);

            if (!$deploySuccess) {
                throw new \RuntimeException('Failed to deploy instance on target server.');
            }

            // Update DNS record to point to new server IP
            if ($instance->domains()->exists()) {
                $domain = $instance->domains()->first();
                if ($domain && $domain->provider_record_id) {
                    try {
                        $dns = new CloudflareDns();
                        $dns->updateRecord($domain->provider_record_id, [
                            'content' => $targetServer->public_ip,
                        ]);

                        Log::info('DNS record updated to point to new server', [
                            'instance_id' => $instance->id,
                            'new_server_ip' => $targetServer->public_ip,
                            'dns_record_id' => $domain->provider_record_id,
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to update DNS record (instance is deployed but DNS may be wrong)', [
                            'instance_id' => $instance->id,
                            'error' => $e->getMessage(),
                        ]);
                        // Continue anyway - DNS can be updated manually
                    }
                } else {
                    // Create DNS record if it doesn't exist
                    try {
                        $dns = new CloudflareDns();
                        $dnsRecord = $dns->ensureARecord($instance->subdomain, $targetServer->public_ip);

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
                    } catch (\Exception $e) {
                        Log::warning('Failed to create DNS record', [
                            'instance_id' => $instance->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Delete instance from old server
            if ($oldServer) {
                try {
                    $oldDeployer = new NodeRedDeployer($oldServer);
                    $oldDeployer->delete($instance);
                    
                    Log::info('Instance deleted from old server', [
                        'instance_id' => $instance->id,
                        'old_server_id' => $oldServer->id,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete instance from old server (may need manual cleanup)', [
                        'instance_id' => $instance->id,
                        'old_server_id' => $oldServer->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue anyway - can be cleaned up manually
                }
            }

            // Update instance status
            $instance->update([
                'status' => 'active',
                'deployed_at' => now(),
            ]);

            Log::info('Instance migration completed successfully', [
                'instance_id' => $instance->id,
                'old_server_id' => $oldServer->id,
                'target_server_id' => $targetServer->id,
                'user_id' => $this->userId,
            ]);
        } catch (\Exception $e) {
            // Revert server assignment on failure
            $instance->update([
                'server_id' => $oldServer->id,
                'status' => 'error',
            ]);

            Log::error('Instance migration failed', [
                'instance_id' => $instance->id,
                'target_server_id' => $targetServer->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('MoveNodeRedInstanceJob failed permanently', [
            'instance_id' => $this->instanceId,
            'target_server_id' => $this->targetServerId,
            'error' => $exception->getMessage(),
        ]);
    }
}

