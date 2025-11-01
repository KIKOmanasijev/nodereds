<?php

namespace App\Livewire\Admin\Instances;

use App\Jobs\DeployNodeRedInstanceJob;
use App\Models\NodeRedInstance;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public NodeRedInstance $instance;

    public function mount(NodeRedInstance $instance): void
    {
        $this->instance = $instance;
        Gate::authorize('view', $instance);
    }

    public function retryDeployment(): void
    {
        Gate::authorize('update', $this->instance);

        // Only allow retry if instance is in error or pending state
        if (!in_array($this->instance->status, ['error', 'pending'])) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Cannot retry deployment. Instance status: ' . $this->instance->status,
            ]);
            return;
        }

        // Reset instance status to pending
        $this->instance->update(['status' => 'pending']);

        // Dispatch new deployment job
        DeployNodeRedInstanceJob::dispatch($this->instance->id, auth()->id());

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Deployment retry initiated.',
        ]);

        // Refresh the instance
        $this->instance->refresh();
    }

    public function render()
    {
        $this->instance->load([
            'user', 
            'server', 
            'plan', 
            'deployments' => function ($query) {
                $query->latest()->limit(10);
            }, 
            'domains', 
            'latestDeployment'
        ]);

        return view('livewire.admin.instances.show', [
            'instance' => $this->instance,
        ]);
    }
}
