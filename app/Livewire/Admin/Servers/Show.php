<?php

namespace App\Livewire\Admin\Servers;

use App\Models\Server;
use App\Services\Hetzner\HetznerClient;
use App\Services\Provisioning\TraefikBootstrapper;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Server $server;

    public function mount(Server $server): void
    {
        $this->server = $server;
        Gate::authorize('view', $server);
    }

    public function restartTraefik(): void
    {
        Gate::authorize('update', $this->server);

        if ($this->server->status !== 'active') {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Cannot restart Traefik. Server is not active.',
            ]);
            return;
        }

        try {
            $bootstrapper = new TraefikBootstrapper($this->server);
            $success = $bootstrapper->bootstrap();

            if ($success) {
                $this->dispatch('notify', [
                    'type' => 'success',
                    'message' => 'Traefik restarted successfully.',
                ]);
            } else {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'Failed to restart Traefik.',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to restart Traefik', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error restarting Traefik: ' . $e->getMessage(),
            ]);
        }

        $this->server->refresh();
    }

    public function restartServer(): void
    {
        Gate::authorize('update', $this->server);

        if ($this->server->status !== 'active') {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Cannot restart server. Server is not active.',
            ]);
            return;
        }

        try {
            $hetznerClient = app(HetznerClient::class);
            $success = $hetznerClient->rebootServer((int) $this->server->provider_id);

            if ($success) {
                $this->dispatch('notify', [
                    'type' => 'success',
                    'message' => 'Server restart initiated. This may take a few minutes.',
                ]);
            } else {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'Failed to restart server.',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to restart server', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error restarting server: ' . $e->getMessage(),
            ]);
        }

        $this->server->refresh();
    }

    public function render()
    {
        $this->server->load([
            'nodeRedInstances' => function ($query) {
                $query->with(['plan', 'user'])->latest();
            }
        ]);

        // Calculate server monthly cost
        $monthlyCostCents = null;
        $regionInfo = null;
        if ($this->server->server_type && $this->server->provider_id) {
            try {
                $hetznerClient = app(HetznerClient::class);
                $pricing = $hetznerClient->getServerPricing($this->server->server_type, $this->server->region);
                if ($pricing && isset($pricing['price_monthly']['gross'])) {
                    // Price is in EUR, convert to cents (multiply by 100)
                    $monthlyCostEur = (float) $pricing['price_monthly']['gross'];
                    $monthlyCostCents = (int) round($monthlyCostEur * 100);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get server pricing', [
                    'server_id' => $this->server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Get region information
        if ($this->server->region) {
            try {
                $hetznerClient = app(HetznerClient::class);
                $locations = $hetznerClient->getLocations();
                $regionInfo = collect($locations)->firstWhere('name', $this->server->region);
            } catch (\Exception $e) {
                Log::warning('Failed to get location info', [
                    'server_id' => $this->server->id,
                    'region' => $this->server->region,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Calculate total instances profit (sum of monthly prices)
        $totalMonthlyRevenueCents = $this->server->nodeRedInstances
            ->where('status', 'active')
            ->sum(function ($instance) {
                return $instance->plan->monthly_price_cents ?? 0;
            });

        // Calculate allocated resources (sum of instance allocations)
        $allocatedMemoryMb = $this->server->allocated_memory_mb;
        $allocatedDiskGb = $this->server->allocated_disk_gb;

        return view('livewire.admin.servers.show', [
            'server' => $this->server,
            'monthlyCostCents' => $monthlyCostCents,
            'totalMonthlyRevenueCents' => $totalMonthlyRevenueCents,
            'regionInfo' => $regionInfo,
            'allocatedMemoryMb' => $allocatedMemoryMb,
            'allocatedDiskGb' => $allocatedDiskGb,
        ]);
    }
}

