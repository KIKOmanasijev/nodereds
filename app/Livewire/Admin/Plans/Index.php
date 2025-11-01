<?php

namespace App\Livewire\Admin\Plans;

use App\Models\Plan;
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
        Gate::authorize('viewAny', Plan::class);
    }

    public function render()
    {
        $plans = Plan::orderBy('sort_order')
            ->paginate(15);

        return view('livewire.admin.plans.index', [
            'plans' => $plans,
        ]);
    }
}
