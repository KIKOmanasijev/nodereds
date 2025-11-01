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

    public function checkServerStatus(): void
    {
        Gate::authorize('view', $this->server);

        try {
            $success = $this->server->syncStatusFromHetzner();

            if ($success) {
                $this->server->refresh();
                $this->dispatch('notify', [
                    'type' => 'success',
                    'message' => 'Server status updated successfully.',
                ]);
            } else {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'Failed to check server status. Please check logs.',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to check server status', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error checking server status: ' . $e->getMessage(),
            ]);
        }
    }

    public function deleteServer(): void
    {
        Gate::authorize('delete', $this->server);

        // Check if server has instances
        $instanceCount = $this->server->nodeRedInstances()->count();
        if ($instanceCount > 0) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => "Cannot delete server. It has {$instanceCount} Node-RED instance(s). Please delete all instances first.",
            ]);
            return;
        }

        try {
            // Delete from Hetzner if provider_id exists (with retries)
            $hetznerDeleteSuccess = false;
            if ($this->server->provider_id) {
                $hetznerClient = app(HetznerClient::class);
                $maxAttempts = 3;
                $attempt = 0;
                
                Log::info('Deleting server from Hetzner Cloud', [
                    'server_id' => $this->server->id,
                    'provider_id' => $this->server->provider_id,
                    'server_name' => $this->server->name,
                    'max_attempts' => $maxAttempts,
                ]);
                
                while ($attempt < $maxAttempts && !$hetznerDeleteSuccess) {
                    $attempt++;
                    
                    Log::info('Attempting to delete server from Hetzner Cloud', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                    ]);
                    
                    $success = $hetznerClient->deleteServer((int) $this->server->provider_id);
                    
                    if ($success) {
                        $hetznerDeleteSuccess = true;
                        Log::info('Successfully deleted server from Hetzner Cloud', [
                            'server_id' => $this->server->id,
                            'provider_id' => $this->server->provider_id,
                            'attempt' => $attempt,
                        ]);
                    } else {
                        Log::warning('Failed to delete server from Hetzner Cloud', [
                            'server_id' => $this->server->id,
                            'provider_id' => $this->server->provider_id,
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
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'attempts' => $maxAttempts,
                    ]);
                }
            } else {
                Log::warning('Cannot delete server from Hetzner: no provider_id', [
                    'server_id' => $this->server->id,
                    'server_name' => $this->server->name,
                ]);
            }

            // Delete from database (always delete, even if Hetzner deletion failed)
            $serverName = $this->server->name;
            $serverId = $this->server->id;
            $hadProviderId = !empty($this->server->provider_id);
            $this->server->delete();

            Log::info('Server deleted successfully from database', [
                'server_name' => $serverName,
                'server_id' => $serverId,
                'hetzner_deleted' => $hetznerDeleteSuccess,
            ]);

            if ($hetznerDeleteSuccess || !$hadProviderId) {
                $this->dispatch('notify', [
                    'type' => 'success',
                    'message' => 'Server deleted successfully from Hetzner Cloud and database.',
                ]);
            } else {
                $this->dispatch('notify', [
                    'type' => 'warning',
                    'message' => 'Server deleted from database. Failed to delete from Hetzner Cloud after 3 attempts. Please check Hetzner Cloud manually.',
                ]);
            }

            // Redirect to servers index
            $this->redirect(route('admin.servers.index'));
        } catch (\Exception $e) {
            Log::error('Failed to delete server', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error deleting server: ' . $e->getMessage(),
            ]);
        }
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

