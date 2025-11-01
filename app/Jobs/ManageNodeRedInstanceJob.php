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

        // Create deployment record for this action
        $deployment = Deployment::create([
            'node_red_instance_id' => $instance->id,
            'created_by' => $this->userId,
            'state' => 'deploying',
            'started_at' => now(),
            'metadata' => [
                'action' => $this->action,
                'attempt' => $this->attempts(),
            ],
        ]);

        // Update instance status to deploying while action is in progress
        $instance->update(['status' => 'deploying']);

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

            // Update instance status based on action
            if (in_array($this->action, ['start', 'restart'])) {
                $instance->update(['status' => 'active']);
            } elseif ($this->action === 'stop') {
                // Keep status as deploying for now, will be updated when container is verified stopped
                // Actually, let's set it to active but the container will be stopped
                // The status 'active' here means the instance exists, not that it's running
                // We could use a different status for stopped instances, but for now keep it simple
                $instance->update(['status' => 'active']);
            }

            // Update deployment record as successful
            $deployment->update([
                'state' => 'success',
                'completed_at' => now(),
            ]);

            Log::info('Successfully managed instance', [
                'instance_id' => $instance->id,
                'action' => $this->action,
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

            Log::error('Failed to manage instance', [
                'instance_id' => $instance->id,
                'action' => $this->action,
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

        Log::error('ManageNodeRedInstanceJob failed permanently', [
            'instance_id' => $this->instanceId,
            'action' => $this->action,
            'error' => $exception->getMessage(),
            'deployment_id' => $deployment?->id,
        ]);
    }
}
