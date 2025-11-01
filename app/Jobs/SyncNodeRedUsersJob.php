<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\NodeRedInstance;
use App\Services\Provisioning\NodeRedDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncNodeRedUsersJob implements ShouldQueue
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
    public int $timeout = 120; // 2 minutes should be enough for sync and restart

    public function __construct(
        public readonly int $instanceId,
        public readonly ?int $userId = null
    ) {
    }

    public function handle(): void
    {
        $instance = NodeRedInstance::findOrFail($this->instanceId);
        $instance->load(['server', 'nodeRedUsers']);

        if (!$instance->server) {
            Log::error('Cannot sync users: Instance has no server assigned', [
                'instance_id' => $instance->id,
            ]);
            throw new \RuntimeException('Instance has no server assigned.');
        }

        // Create deployment record for this action
        $deployment = Deployment::create([
            'node_red_instance_id' => $instance->id,
            'created_by' => $this->userId,
            'state' => 'deploying',
            'started_at' => now(),
            'metadata' => [
                'action' => 'sync_users',
                'attempt' => $this->attempts(),
            ],
        ]);

        // Update instance status to deploying while action is in progress
        $instance->update(['status' => 'deploying']);

        try {
            $deployer = new NodeRedDeployer($instance->server);
            
            // Sync users to settings.js
            $success = $deployer->syncUsers($instance);
            
            if (!$success) {
                throw new \RuntimeException('Failed to sync users to settings.js.');
            }
            
            // Restart container to apply new settings
            // The restart method will wait for Node-RED to be healthy before returning
            $restartSuccess = $deployer->restart($instance);
            
            if (!$restartSuccess) {
                Log::warning('Users synced but failed to restart container', [
                    'instance_id' => $instance->id,
                ]);
                throw new \RuntimeException('Users synced but failed to restart container.');
            }

            // Update instance status to active after successful restart
            $instance->update(['status' => 'active']);

            // Update deployment record as successful
            $deployment->update([
                'state' => 'success',
                'completed_at' => now(),
            ]);

            Log::info('Successfully synced users and restarted container', [
                'instance_id' => $instance->id,
                'user_id' => $this->userId,
                'deployment_id' => $deployment->id,
            ]);
        } catch (\Exception $e) {
            // Update deployment record as failed
            $deployment->update([
                'state' => 'failed',
                'completed_at' => now(),
                'reason' => $e->getMessage(),
            ]);

            // Update instance status to error
            $instance->update(['status' => 'error']);

            Log::error('Failed to sync users', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'deployment_id' => $deployment->id,
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $instance = NodeRedInstance::find($this->instanceId);
        if ($instance) {
            $instance->update(['status' => 'error']);
        }

        // Find and update the deployment record if it exists
        $deployment = Deployment::where('node_red_instance_id', $this->instanceId)
            ->where('state', 'deploying')
            ->latest()
            ->first();

        if ($deployment) {
            $deployment->update([
                'state' => 'failed',
                'completed_at' => now(),
                'reason' => $exception->getMessage(),
            ]);
        }

        Log::error('SyncNodeRedUsersJob failed permanently', [
            'instance_id' => $this->instanceId,
            'error' => $exception->getMessage(),
            'deployment_id' => $deployment?->id,
        ]);
    }
}
