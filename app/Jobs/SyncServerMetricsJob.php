<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\SSH\Ssh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncServerMetricsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $serverId = null
    ) {
    }

    public function handle(): void
    {
        $servers = $this->serverId
            ? Server::where('id', $this->serverId)->get()
            : Server::where('status', 'active')->get();

        foreach ($servers as $server) {
            try {
                $this->syncMetrics($server);
            } catch (\Exception $e) {
                Log::error('Failed to sync server metrics', [
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function syncMetrics(Server $server): void
    {
        if (!$server->public_ip) {
            return;
        }

        $ssh = new Ssh($server->public_ip);

        // Get memory usage
        $memoryResult = $ssh->execute("free -m | awk 'NR==2{printf \"%.0f\", \$3*1024/1024}'", false);
        $usedMemoryMb = (int) trim($memoryResult->getOutput());

        // Get disk usage
        $diskResult = $ssh->execute("df -BG / | awk 'NR==2 {print \$3}' | sed 's/G//'", false);
        $usedDiskGb = (int) trim($diskResult->getOutput());

        // Get total disk
        $diskTotalResult = $ssh->execute("df -BG / | awk 'NR==2 {print \$2}' | sed 's/G//'", false);
        $totalDiskGb = (int) trim($diskTotalResult->getOutput());

        // Update server
        $server->update([
            'ram_mb_used' => $usedMemoryMb,
            'disk_gb_used' => $usedDiskGb,
            'disk_gb_total' => $server->disk_gb_total ?: $totalDiskGb,
        ]);
    }
}
