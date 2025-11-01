<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\NodeRedInstance;
use App\Services\DNS\CloudflareDns;
use App\Services\Provisioning\NodeRedDeployer;
use App\Services\Scheduling\CapacityPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteNodeRedInstanceJob implements ShouldQueue
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
    public int $timeout = 300; // 5 minutes

    public function __construct(
        public readonly int $instanceId,
        public readonly ?int $userId = null
    ) {
        $this->onQueue('delete');
    }

    public function handle(): void
    {
        $instance = NodeRedInstance::findOrFail($this->instanceId);
        $instance->load(['server', 'plan', 'domains']);

        // Check if instance is already deleted
        if ($instance->status === 'deleted') {
            Log::warning('Instance is already deleted', ['instance_id' => $instance->id]);
            return;
        }

        try {
            // Update status to deleting
            $instance->update(['status' => 'deleting']);

            // Delete Docker container and files if server exists
            if ($instance->server) {
                try {
                    $deployer = new NodeRedDeployer($instance->server);
                    $deployer->delete($instance);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete Docker container', [
                        'instance_id' => $instance->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with cleanup even if Docker deletion fails
                }
            }

            // Delete DNS records
            foreach ($instance->domains as $domain) {
                try {
                    if ($domain->provider_record_id) {
                        $dns = new CloudflareDns();
                        $dns->deleteRecord($domain->provider_record_id);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to delete DNS record', [
                        'domain_id' => $domain->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with cleanup even if DNS deletion fails
                }
            }

            // Release server capacity
            if ($instance->server) {
                try {
                    $capacityPlanner = app(CapacityPlanner::class);
                    $capacityPlanner->releaseServerCapacity(
                        $instance->server,
                        $instance->memory_mb,
                        $instance->storage_gb
                    );
                } catch (\Exception $e) {
                    Log::warning('Failed to release server capacity', [
                        'instance_id' => $instance->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Delete related records
            $instance->deployments()->delete();
            $instance->domains()->delete();

            // Delete the instance
            $instance->delete();

            Log::info('Node-RED instance deleted successfully', [
                'instance_id' => $this->instanceId,
            ]);
        } catch (\Exception $e) {
            Log::error('Node-RED instance deletion failed', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark instance as error if deletion fails
            $instance->update(['status' => 'error']);

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
            Log::error('Node-RED instance deletion job failed permanently', [
                'instance_id' => $instance->id,
                'attempts' => $this->attempts(),
                'error' => $exception?->getMessage(),
            ]);
        }
    }
}

