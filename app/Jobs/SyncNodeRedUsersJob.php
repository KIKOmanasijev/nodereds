<?php

namespace App\Jobs;

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

        try {
            $deployer = new NodeRedDeployer($instance->server);
            
            // Sync users to settings.js
            $success = $deployer->syncUsers($instance);
            
            if (!$success) {
                throw new \RuntimeException('Failed to sync users to settings.js.');
            }
            
            // Restart container to apply new settings
            $restartSuccess = $deployer->restart($instance);
            
            if (!$restartSuccess) {
                Log::warning('Users synced but failed to restart container', [
                    'instance_id' => $instance->id,
                ]);
                throw new \RuntimeException('Users synced but failed to restart container.');
            }

            Log::info('Successfully synced users and restarted container', [
                'instance_id' => $instance->id,
                'user_id' => $this->userId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to sync users', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncNodeRedUsersJob failed permanently', [
            'instance_id' => $this->instanceId,
            'error' => $exception->getMessage(),
        ]);
    }
}
