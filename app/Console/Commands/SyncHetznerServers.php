<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Hetzner\HetznerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncHetznerServers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'servers:sync-hetzner 
                            {--dry-run : Show what would be synced without actually creating records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync servers from Hetzner Cloud that are not in the database';

    /**
     * Execute the console command.
     */
    public function handle(HetznerClient $hetznerClient): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Fetching servers from Hetzner Cloud...');
        $hetznerServers = $hetznerClient->listServers();

        $this->info("Found " . count($hetznerServers) . " servers on Hetzner Cloud.");

        $synced = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($hetznerServers as $hetznerServer) {
            // Check if server already exists in database
            $existingServer = Server::where('provider_id', (string) $hetznerServer['id'])->first();

            if ($existingServer) {
                $this->line("Skipping {$hetznerServer['name']} (already exists in database)");
                $skipped++;
                continue;
            }

            // Only sync servers that match our naming pattern
            if (!str_starts_with($hetznerServer['name'], 'nr-server-')) {
                $this->line("Skipping {$hetznerServer['name']} (doesn't match naming pattern)");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->info("Would sync: {$hetznerServer['name']} (ID: {$hetznerServer['id']})");
                $synced++;
                continue;
            }

            try {
                // Get server specs
                $serverType = $hetznerServer['server_type'] ?? 'unknown';
                $serverSpecs = $this->getServerSpecs($hetznerClient, $serverType);

                // Safely extract private IP
                $privateIp = null;
                if (isset($hetznerServer['private_net']) && is_array($hetznerServer['private_net']) && !empty($hetznerServer['private_net'])) {
                    $privateIp = $hetznerServer['private_net'][0]['ip'] ?? null;
                }

                // Determine status based on Hetzner status
                $status = match ($hetznerServer['status'] ?? 'unknown') {
                    'running' => 'active',
                    'starting' => 'provisioning',
                    'stopping', 'off' => 'error',
                    default => 'provisioning',
                };

                Server::create([
                    'provider_id' => (string) $hetznerServer['id'],
                    'name' => $hetznerServer['name'],
                    'public_ip' => $hetznerServer['public_net']['ipv4']['ip'] ?? null,
                    'private_ip' => $privateIp,
                    'region' => $hetznerServer['datacenter']['location']['name'] ?? config('provisioning.hetzner.default_region', 'nbg1'),
                    'server_type' => $serverType,
                    'ram_mb_total' => $serverSpecs['memory_mb'] ?? 0,
                    'disk_gb_total' => $serverSpecs['disk_gb'] ?? 0,
                    'ram_mb_used' => 0,
                    'disk_gb_used' => 0,
                    'status' => $status,
                    'provisioned_at' => isset($hetznerServer['created']) ? \Carbon\Carbon::parse($hetznerServer['created']) : now(),
                ]);

                $this->info("Synced: {$hetznerServer['name']} (ID: {$hetznerServer['id']})");
                $synced++;
            } catch (\Exception $e) {
                $this->error("Failed to sync {$hetznerServer['name']}: {$e->getMessage()}");
                Log::error('Failed to sync Hetzner server', [
                    'hetzner_server_id' => $hetznerServer['id'],
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->line("  Synced: {$synced}");
        $this->line("  Skipped: {$skipped}");
        if ($errors > 0) {
            $this->line("  Errors: {$errors}");
        }

        return Command::SUCCESS;
    }

    /**
     * Get server specs from server type.
     */
    private function getServerSpecs(HetznerClient $hetznerClient, string $serverType): array
    {
        try {
            $serverTypes = $hetznerClient->getServerTypes();

            foreach ($serverTypes as $type) {
                if ($type['name'] === $serverType) {
                    return [
                        'memory_mb' => ($type['memory'] ?? 0) * 1024, // Convert GB to MB
                        'disk_gb' => $type['disk'] ?? 0,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get server specs', [
                'server_type' => $serverType,
                'error' => $e->getMessage(),
            ]);
        }

        return ['memory_mb' => 0, 'disk_gb' => 0];
    }
}
