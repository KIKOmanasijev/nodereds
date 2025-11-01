<?php

namespace App\Livewire\Admin\Servers;

use App\Models\Server;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public function mount(): void
    {
        Gate::authorize('viewAny', Server::class);
    }

    public function checkServerStatus(int $serverId): void
    {
        $server = Server::findOrFail($serverId);
        Gate::authorize('view', $server);

        try {
            $success = $server->syncStatusFromHetzner();

            if ($success) {
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
                'server_id' => $serverId,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error checking server status: ' . $e->getMessage(),
            ]);
        }

        $this->resetPage();
    }

    public function render()
    {
        $servers = Server::latest()
            ->paginate(15);

        return view('livewire.admin.servers.index', [
            'servers' => $servers,
        ]);
    }
}
