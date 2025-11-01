<?php

namespace App\Jobs;

use App\Models\NodeRedInstance;
use App\Services\Provisioning\NodeRedDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ManageNodeRedInstanceJob implements ShouldQueue
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
    public int $timeout = 120; // 2 minutes should be enough

    public function __construct(
        public readonly int $instanceId,
        public readonly string $action, // 'start', 'stop', or 'restart'
        public readonly ?int $userId = null
    ) {
        if (!in_array($action, ['start', 'stop', 'restart'])) {
            throw new \InvalidArgumentException("Invalid action: {$action}. Must be 'start', 'stop', or 'restart'.");
        }
    }

    public function handle(): void
    {
        $instance = NodeRedInstance::findOrFail($this->instanceId);
        $instance->load(['server']);

        if (!$instance->server) {
            Log::error('Cannot manage instance: Instance has no server assigned', [
                'instance_id' => $instance->id,
                'action' => $this->action,
            ]);
            throw new \RuntimeException('Instance has no server assigned.');
        }

        try {
            $deployer = new NodeRedDeployer($instance->server);
            
            $success = false;
            switch ($this->action) {
                case 'start':
                    $success = $deployer->start($instance);
                    break;
                case 'stop':
                    $success = $deployer->stop($instance);
                    break;
                case 'restart':
                    $success = $deployer->restart($instance);
                    break;
            }
            
            if (!$success) {
                throw new \RuntimeException("Failed to {$this->action} instance.");
            }

            Log::info('Successfully managed instance', [
                'instance_id' => $instance->id,
                'action' => $this->action,
                'user_id' => $this->userId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to manage instance', [
                'instance_id' => $instance->id,
                'action' => $this->action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ManageNodeRedInstanceJob failed permanently', [
            'instance_id' => $this->instanceId,
            'action' => $this->action,
            'error' => $exception->getMessage(),
        ]);
    }
}
