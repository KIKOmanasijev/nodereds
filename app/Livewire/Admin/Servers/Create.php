<?php

namespace App\Livewire\Admin\Servers;

use App\Models\Server;
use App\Services\Hetzner\HetznerClient;
use App\Services\Scheduling\CapacityPlanner;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public int $currentStep = 1;
    public int $totalSteps = 3;
    
    // Step 1: Server Type Selection
    public string $serverType = '';
    
    // Step 2: Location Selection
    public string $location = '';
    
    // Step 3: Review
    public string $serverName = '';

    public function mount(): void
    {
        Gate::authorize('create', Server::class);
        
        // Set defaults
        $this->location = config('provisioning.hetzner.default_region', 'nbg1');
        $this->serverName = 'nr-server-' . now()->format('YmdHis');
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1) {
            $this->validate([
                'serverType' => ['required', 'string'],
            ]);
        } elseif ($this->currentStep === 2) {
            $this->validate([
                'location' => ['required', 'string'],
            ]);
        }
        
        if ($this->currentStep < $this->totalSteps) {
            $this->currentStep++;
        }
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step >= 1 && $step <= $this->totalSteps && $step <= $this->currentStep) {
            $this->currentStep = $step;
        }
    }

    public function save(): void
    {
        // Validate all fields
        $this->validate([
            'serverType' => ['required', 'string'],
            'location' => ['required', 'string'],
            'serverName' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
        ]);

        // FORBIDDEN: Do not create Hetzner servers in local environment
        if (app()->environment('local')) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Server creation is forbidden in local environment.',
            ]);
            return;
        }

        try {
            $hetznerClient = app(HetznerClient::class);
            
            Log::info('Creating new server via wizard', [
                'server_type' => $this->serverType,
                'location' => $this->location,
                'server_name' => $this->serverName,
            ]);

            $serverData = $hetznerClient->createServer([
                'name' => $this->serverName,
                'server_type' => $this->serverType,
                'image' => config('provisioning.hetzner.default_image', 'ubuntu-24.04'),
                'location' => $this->location,
            ]);

            $serverId = $serverData['id'] ?? null;

            // Get server type name (it might be an object or string)
            $serverTypeName = is_array($serverData['server_type']) 
                ? ($serverData['server_type']['name'] ?? $this->serverType)
                : ($serverData['server_type'] ?? $this->serverType);

            // Get server specs
            $capacityPlanner = app(CapacityPlanner::class);
            $serverSpecs = $capacityPlanner->getServerSpecs($serverTypeName);

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
                'region' => $serverData['datacenter']['location']['name'] ?? $this->location,
                'server_type' => $serverTypeName,
                'ram_mb_total' => $serverSpecs['memory_mb'] ?? 0,
                'disk_gb_total' => $serverSpecs['disk_gb'] ?? 0,
                'ram_mb_used' => 0,
                'disk_gb_used' => 0,
                'status' => 'provisioning',
            ]);

            // Wait for server to be ready (basic check)
            $ready = $hetznerClient->waitForServer($serverData['id'], 300);
            if (!$ready) {
                $server->update(['status' => 'error']);
                throw new \RuntimeException('Server provisioning timeout');
            }

            // Update IPs if they weren't set initially
            $serverData = $hetznerClient->getServer($serverData['id']);
            
            // Safely extract private IP again
            $privateIp = null;
            if (isset($serverData['private_net']) && is_array($serverData['private_net']) && !empty($serverData['private_net'])) {
                $privateIp = $serverData['private_net'][0]['ip'] ?? null;
            }

            $server->update([
                'public_ip' => $serverData['public_net']['ipv4']['ip'] ?? $server->public_ip,
                'private_ip' => $privateIp ?? $server->private_ip,
                'status' => 'provisioning',
                'provisioned_at' => now(),
            ]);

            // Wait additional time for server to fully boot and SSH to be ready
            Log::info('Waiting for server to be fully ready before bootstrapping', [
                'server_id' => $server->id,
                'wait_seconds' => 60,
            ]);
            sleep(60);

            // Dispatch provisioning job to bootstrap Traefik
            \App\Jobs\ProvisionServerJob::dispatch($server->id);

            session()->flash('message', 'Server created successfully. Provisioning started.');

            $this->redirect(route('admin.servers.index'), navigate: true);
        } catch (\Exception $e) {
            Log::error('Failed to create server via wizard', [
                'server_type' => $this->serverType,
                'location' => $this->location,
                'server_name' => $this->serverName,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to create server: ' . $e->getMessage(),
            ]);
        }
    }

    public function render()
    {
        $hetznerClient = app(HetznerClient::class);
        $serverTypes = collect($hetznerClient->getServerTypes())
            ->filter(fn($type) => !($type['deprecated'] ?? false))
            ->sortBy(fn($type) => $type['memory'] ?? 0)
            ->values()
            ->all();
        
        $locations = $hetznerClient->getLocations();
        
        $selectedServerType = collect($serverTypes)->firstWhere('name', $this->serverType);
        $selectedLocation = collect($locations)->firstWhere('name', $this->location);
        
        // Get pricing for selected server type and location
        $pricing = null;
        if ($this->serverType && $this->location && $selectedServerType) {
            $pricing = $hetznerClient->getServerPricing($this->serverType, $this->location);
        }

        return view('livewire.admin.servers.create', [
            'serverTypes' => $serverTypes,
            'locations' => $locations,
            'selectedServerType' => $selectedServerType,
            'selectedLocation' => $selectedLocation,
            'pricing' => $pricing,
        ]);
    }
}

