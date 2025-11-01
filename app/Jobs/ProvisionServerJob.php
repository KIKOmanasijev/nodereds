<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Hetzner\HetznerClient;
use App\Services\Provisioning\TraefikBootstrapper;
use App\Services\Scheduling\CapacityPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProvisionServerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $serverId
    ) {
    }

    public function handle(): void
    {
        $server = Server::findOrFail($this->serverId);

        if ($server->status !== 'provisioning') {
            Log::warning('Server is not in provisioning state', ['server_id' => $server->id]);
            return;
        }

        try {
            // Bootstrap Traefik
            $bootstrapper = new TraefikBootstrapper($server);
            $success = $bootstrapper->bootstrap();

            if ($success) {
                $server->update(['status' => 'active']);
            } else {
                $server->update(['status' => 'error']);
            }
        } catch (\Exception $e) {
            Log::error('Server provisioning failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
            $server->update(['status' => 'error']);
        }
    }
}
