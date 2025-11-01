<?php

namespace App\Livewire\Admin\Servers;

use App\Models\Server;
use Illuminate\Support\Facades\Gate;
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

    public function render()
    {
        $servers = Server::latest()
            ->paginate(15);

        return view('livewire.admin.servers.index', [
            'servers' => $servers,
        ]);
    }
}
