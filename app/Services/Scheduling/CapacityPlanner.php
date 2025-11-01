<?php

namespace App\Services\Scheduling;

use App\Jobs\ProvisionServerJob;
use App\Models\Plan;
use App\Models\Server;
use App\Services\Hetzner\HetznerClient;
use Illuminate\Support\Facades\Log;

class CapacityPlanner
{
    private HetznerClient $hetznerClient;

    public function __construct(HetznerClient $hetznerClient)
    {
        $this->hetznerClient = $hetznerClient;
    }

    /**
     * Find a server that can fit the instance, or create a new one.
     * If serverId is provided, returns that server without capacity checks (admin override).
     */
    public function findOrCreateServer(Plan $plan, ?int $serverId = null): Server
    {
        // If server ID is explicitly provided (admin override), use it without capacity checks
        if ($serverId) {
            $server = Server::find($serverId);
            
            if (!$server) {
                throw new \RuntimeException("Server ID {$serverId} not found");
            }
            
            if ($server->status !== 'active' && $server->status !== 'provisioning') {
                throw new \RuntimeException("Server {$server->name} is not in a deployable state. Status: {$server->status}");
            }
            
            Log::info('Using admin-selected server (override)', [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'instance_memory_mb' => $plan->memory_mb,
                'instance_storage_gb' => $plan->storage_gb,
            ]);
            
            return $server;
        }
        
        // For testing: use hardcoded server ID
        $hardcodedServerId = config('provisioning.testing.hardcoded_server_id', null);
        
        if ($hardcodedServerId && app()->isLocal()) {
            $server = Server::find($hardcodedServerId);
            
            if ($server) {
                Log::info('Using hardcoded server', [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                ]);
                return $server;
            } else {
                throw new \RuntimeException("Hardcoded server ID {$hardcodedServerId} not found in database. Please create the server first or update HARDCODED_SERVER_ID in .env");
            }
        }

        // For testing: if enabled, always try to reuse existing servers first
        $reuseExisting = config('provisioning.testing.reuse_existing_servers', false);

        if ($reuseExisting) {
            // Find any active server, regardless of capacity
            $server = Server::where('status', 'active')->first();
            
            if ($server) {
                Log::info('Testing mode: Reusing existing server', [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                ]);
                return $server;
            }
        }

        // First, try to find an existing server with capacity
        $server = $this->findAvailableServer($plan->memory_mb, $plan->storage_gb);

        if ($server) {
            return $server;
        }

        // No available server, create a new one (only in production)
        if (app()->environment('local')) {
            throw new \RuntimeException('No available server found and server creation is forbidden in local environment. Please set HARDCODED_SERVER_ID in .env to use an existing server.');
        }

        return $this->createNewServer($plan);
    }

    /**
     * Find an available server that can fit the instance.
     */
    public function findAvailableServer(int $memoryMb, int $storageGb): ?Server
    {
        $servers = Server::where('status', 'active')
            ->get()
            ->filter(function (Server $server) use ($memoryMb, $storageGb) {
                return $server->canFitInstance($memoryMb, $storageGb);
            })
            ->sortByDesc(function (Server $server) {
                // Prefer servers with more available capacity
                return $server->available_memory_mb + ($server->available_disk_gb * 100);
            });

        return $servers->first();
    }

    /**
     * Create a new server for the instance.
     */
    public function createNewServer(Plan $plan): Server
    {
        // FORBIDDEN: Do not create Hetzner servers in local environment
        if (app()->environment('local')) {
            throw new \RuntimeException('Server creation is forbidden in local environment. Use HARDCODED_SERVER_ID in .env to specify an existing server.');
        }

        $serverType = $this->determineServerType($plan);
        $serverId = null;

        try {
            $serverData = $this->hetznerClient->createServer([
                'name' => 'nr-server-' . now()->format('YmdHis'),
                'server_type' => $serverType,
                'image' => config('provisioning.hetzner.default_image', 'ubuntu-24.04'),
                'location' => config('provisioning.hetzner.default_region', 'nbg1'),
            ]);

            $serverId = $serverData['id'] ?? null;

            // Get server type name (it might be an object or string)
            $serverTypeName = is_array($serverData['server_type']) 
                ? ($serverData['server_type']['name'] ?? 'cpx11')
                : ($serverData['server_type'] ?? 'cpx11');

            // Get server specs
            $serverSpecs = $this->getServerSpecs($serverTypeName);

            // Safely extract private IP
            $privateIp = null;
            if (isset($serverData['private_net']) && is_array($serverData['private_net']) && !empty($serverData['private_net'])) {
                $privateIp = $serverData['private_net'][0]['ip'] ?? null;
            }

            // Create Server model
            $server = Server::create([
                'provider_id' => (string) $serverData['id'],
                'name' => $serverData['name'],
                'public_ip' => $serverData['public_net']['ipv4']['ip'] ?? null,
                'private_ip' => $privateIp,
                'region' => $serverData['datacenter']['location']['name'] ?? config('provisioning.hetzner.default_region', 'nbg1'),
                'server_type' => $serverTypeName,
                'ram_mb_total' => $serverSpecs['memory_mb'] ?? 0,
                'disk_gb_total' => $serverSpecs['disk_gb'] ?? 0,
                'ram_mb_used' => 0,
                'disk_gb_used' => 0,
                'status' => 'provisioning',
            ]);

            // Wait for server to be ready (basic check)
            $ready = $this->hetznerClient->waitForServer($serverData['id'], 300);
            if (!$ready) {
                $server->update(['status' => 'error']);
                throw new \RuntimeException('Server provisioning timeout');
            }

            // Update IPs if they weren't set initially
            $serverData = $this->hetznerClient->getServer($serverData['id']);
            
            // Safely extract private IP again
            $privateIp = null;
            if (isset($serverData['private_net']) && is_array($serverData['private_net']) && !empty($serverData['private_net'])) {
                $privateIp = $serverData['private_net'][0]['ip'] ?? null;
            }

            $server->update([
                'public_ip' => $serverData['public_net']['ipv4']['ip'] ?? $server->public_ip,
                'private_ip' => $privateIp ?? $server->private_ip,
                'status' => 'provisioning', // Keep as provisioning until Traefik is bootstrapped
                'provisioned_at' => now(),
            ]);

            // Dispatch provisioning job to bootstrap Traefik
            ProvisionServerJob::dispatch($server->id);

            return $server;
        } catch (\Exception $e) {
            // If server was created on Hetzner but database creation failed, log it
            if ($serverId) {
                Log::error('Failed to create Server model after Hetzner server creation', [
                    'hetzner_server_id' => $serverId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            throw $e;
        }
    }

    /**
     * Determine appropriate server type for the plan.
     */
    private function determineServerType(Plan $plan): string
    {
        // Get available server types
        $serverTypes = $this->hetznerClient->getServerTypes();

        // Filter out deprecated server types
        $serverTypes = array_filter($serverTypes, function ($type) {
            return !($type['deprecated'] ?? false);
        });

        // Find the smallest server type that can fit the plan
        // Consider reserved resources
        $reservedMemory = config('provisioning.reserved_resources.memory_mb', 512);
        $reservedDisk = config('provisioning.reserved_resources.disk_gb', 10);

        $requiredMemory = $plan->memory_mb + $reservedMemory;
        $requiredDisk = $plan->storage_gb + $reservedDisk;

        // Sort by memory (ascending)
        usort($serverTypes, function ($a, $b) {
            return ($a['memory'] ?? 0) <=> ($b['memory'] ?? 0);
        });

        foreach ($serverTypes as $type) {
            $memoryGb = $type['memory'] ?? 0;
            $memoryMb = $memoryGb * 1024; // Convert GB to MB
            $diskGb = $type['disk'] ?? 0;

            // Allow some headroom for multiple instances
            if ($memoryMb >= $requiredMemory * 2 && $diskGb >= $requiredDisk * 2) {
                return $type['name'];
            }
        }

        // Fallback to default (which should be non-deprecated)
        $defaultType = config('provisioning.hetzner.default_server_type', 'cpx11');
        
        // Verify the default is not deprecated
        foreach ($serverTypes as $type) {
            if ($type['name'] === $defaultType && !($type['deprecated'] ?? false)) {
                return $defaultType;
            }
        }

        // If default is deprecated, find the smallest non-deprecated type
        foreach ($serverTypes as $type) {
            if (!($type['deprecated'] ?? false)) {
                return $type['name'];
            }
        }

        // Last resort: use cpx11
        return 'cpx11';
    }

    /**
     * Get server specs from server type.
     */
    private function getServerSpecs(string $serverType): array
    {
        $serverTypes = $this->hetznerClient->getServerTypes();

        foreach ($serverTypes as $type) {
            if ($type['name'] === $serverType) {
                return [
                    'memory_mb' => ($type['memory'] ?? 0) * 1024, // Hetzner returns memory in GB, convert to MB
                    'disk_gb' => $type['disk'] ?? 0,
                ];
            }
        }

        return ['memory_mb' => 0, 'disk_gb' => 0];
    }

    /**
     * Update server capacity after instance deployment.
     */
    public function updateServerCapacity(Server $server, int $memoryMb, int $storageGb): void
    {
        $server->increment('ram_mb_used', $memoryMb);
        $server->increment('disk_gb_used', $storageGb);
    }

    /**
     * Release server capacity after instance deletion.
     */
    public function releaseServerCapacity(Server $server, int $memoryMb, int $storageGb): void
    {
        $server->decrement('ram_mb_used', $memoryMb);
        $server->decrement('disk_gb_used', $storageGb);
    }
}

