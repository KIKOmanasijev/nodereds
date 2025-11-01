<?php

namespace App\Livewire\Admin\Instances;

use App\Jobs\DeleteNodeRedInstanceJob;
use App\Models\NodeRedInstance;
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
        Gate::authorize('viewAny', NodeRedInstance::class);
    }

    public function delete(int $instanceId): void
    {
        $instance = NodeRedInstance::findOrFail($instanceId);
        Gate::authorize('delete', $instance);

        // Dispatch delete job
        DeleteNodeRedInstanceJob::dispatch($instanceId, auth()->id());

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Instance deletion initiated.',
        ]);
    }

    public function render()
    {
        $instances = NodeRedInstance::with(['user', 'server', 'plan'])
            ->latest()
            ->paginate(15);

        return view('livewire.admin.instances.index', [
            'instances' => $instances,
        ]);
    }
}
