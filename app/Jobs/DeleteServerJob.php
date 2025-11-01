<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Hetzner\HetznerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteServerJob implements ShouldQueue
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
        public readonly int $serverId,
        public readonly ?int $userId = null
    ) {
        $this->onQueue('delete');
    }

    public function handle(): void
    {
        $server = Server::findOrFail($this->serverId);

        // Check if server has instances
        $instanceCount = $server->nodeRedInstances()->count();
        if ($instanceCount > 0) {
            Log::warning('Cannot delete server: it has instances', [
                'server_id' => $server->id,
                'instance_count' => $instanceCount,
            ]);
            throw new \RuntimeException("Cannot delete server. It has {$instanceCount} Node-RED instance(s). Please delete all instances first.");
        }

        // Delete from Hetzner if provider_id exists (with retries)
        $hetznerDeleteSuccess = false;
        if ($server->provider_id) {
            $hetznerClient = app(HetznerClient::class);
            $maxAttempts = 3;
            $attempt = 0;
            
            Log::info('Deleting server from Hetzner Cloud', [
                'server_id' => $server->id,
                'provider_id' => $server->provider_id,
                'server_name' => $server->name,
                'max_attempts' => $maxAttempts,
            ]);
            
            while ($attempt < $maxAttempts && !$hetznerDeleteSuccess) {
                $attempt++;
                
                Log::info('Attempting to delete server from Hetzner Cloud', [
                    'server_id' => $server->id,
                    'provider_id' => $server->provider_id,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                ]);
                
                $success = $hetznerClient->deleteServer((int) $server->provider_id);
                
                if ($success) {
                    $hetznerDeleteSuccess = true;
                    Log::info('Successfully deleted server from Hetzner Cloud', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'attempt' => $attempt,
                    ]);
                } else {
                    Log::warning('Failed to delete server from Hetzner Cloud', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                    ]);
                    
                    // Wait before retry (except on last attempt)
                    if ($attempt < $maxAttempts) {
                        sleep(2); // Wait 2 seconds before retry
                    }
                }
            }
            
            if (!$hetznerDeleteSuccess) {
                Log::error('Failed to delete server from Hetzner Cloud after all attempts', [
                    'server_id' => $server->id,
                    'provider_id' => $server->provider_id,
                    'attempts' => $maxAttempts,
                ]);
            }
        } else {
            Log::warning('Cannot delete server from Hetzner: no provider_id', [
                'server_id' => $server->id,
                'server_name' => $server->name,
            ]);
        }

        // Delete from database (always delete, even if Hetzner deletion failed)
        $serverName = $server->name;
        $serverId = $server->id;
        $hadProviderId = !empty($server->provider_id);
        $providerId = $server->provider_id;
        $server->delete();

        Log::info('Server deleted successfully from database', [
            'server_name' => $serverName,
            'server_id' => $serverId,
            'hetzner_deleted' => $hetznerDeleteSuccess,
            'user_id' => $this->userId,
        ]);

        if (!$hetznerDeleteSuccess && $hadProviderId) {
            // Log warning if Hetzner deletion failed
            Log::warning('Server deleted from database but Hetzner deletion failed', [
                'server_id' => $serverId,
                'server_name' => $serverName,
                'provider_id' => $providerId,
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $server = Server::find($this->serverId);
        
        Log::error('DeleteServerJob failed permanently', [
            'server_id' => $this->serverId,
            'server_name' => $server?->name ?? 'N/A',
            'error' => $exception->getMessage(),
            'user_id' => $this->userId,
        ]);
    }
}

