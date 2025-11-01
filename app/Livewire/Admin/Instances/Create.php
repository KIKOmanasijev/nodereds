<?php

namespace App\Livewire\Admin\Instances;

use App\Models\NodeRedInstance;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Jobs\DeployNodeRedInstanceJob;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public int $currentStep = 1;
    public int $totalSteps = 3;
    
    // Step 1: Plan Selection
    public int $plan_id = 0;
    
    // Step 2: Basic Details
    public int $user_id = 0;
    public ?int $server_id = null;
    public string $subdomain = '';
    public string $admin_user = 'admin';
    public string $admin_password = '';

    public function mount(): void
    {
        Gate::authorize('create', NodeRedInstance::class);
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1) {
            $this->validate([
                'plan_id' => ['required', 'exists:plans,id'],
            ]);
        } elseif ($this->currentStep === 2) {
            $this->validate([
                'user_id' => ['required', 'exists:users,id'],
                'server_id' => ['nullable', 'integer', 'exists:servers,id'],
                'subdomain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:node_red_instances,subdomain'],
                'admin_user' => ['required', 'string', 'max:255'],
                'admin_password' => ['required', 'string', 'min:8'],
            ]);
            
            // Normalize server_id
            if ($this->server_id === '' || $this->server_id === 0) {
                $this->server_id = null;
            }
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
            'plan_id' => ['required', 'exists:plans,id'],
            'user_id' => ['required', 'exists:users,id'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'subdomain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:node_red_instances,subdomain'],
            'admin_user' => ['required', 'string', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);
        
        // Normalize server_id
        if ($this->server_id === '' || $this->server_id === 0) {
            $this->server_id = null;
        }

        $plan = Plan::findOrFail($this->plan_id);
        $baseDomain = config('provisioning.dns.base_domain', 'nodereds.com');

        // Clean subdomain
        $subdomain = $this->subdomain;
        if (str_contains($subdomain, '.')) {
            $subdomain = explode('.', $subdomain)[0];
        }
        $subdomain = Str::slug($subdomain);

        $instance = NodeRedInstance::create([
            'user_id' => $this->user_id,
            'plan_id' => $this->plan_id,
            'server_id' => $this->server_id,
            'slug' => $subdomain,
            'subdomain' => $subdomain,
            'fqdn' => $subdomain . '.' . $baseDomain,
            'memory_mb' => $plan->memory_mb,
            'storage_gb' => $plan->storage_gb,
            'admin_user' => $this->admin_user,
            'admin_pass_hash' => bcrypt($this->admin_password),
            'credential_secret' => Str::random(32),
            'status' => 'pending',
        ]);

        // Dispatch deployment job
        DeployNodeRedInstanceJob::dispatch($instance->id, auth()->id());

        session()->flash('message', 'Instance created successfully. Deployment started.');

        $this->redirect(route('admin.instances.show', $instance), navigate: true);
    }

    public function render()
    {
        $users = User::orderBy('name')->get();
        $plans = Plan::where('is_active', true)->orderBy('sort_order')->get();
        $servers = Server::whereIn('status', ['active', 'provisioning'])->orderBy('name')->get();
        
        $selectedPlan = $this->plan_id ? Plan::find($this->plan_id) : null;
        $selectedUser = $this->user_id ? User::find($this->user_id) : null;
        $selectedServer = $this->server_id ? Server::find($this->server_id) : null;

        return view('livewire.admin.instances.create', [
            'users' => $users,
            'plans' => $plans,
            'servers' => $servers,
            'selectedPlan' => $selectedPlan,
            'selectedUser' => $selectedUser,
            'selectedServer' => $selectedServer,
        ]);
    }
}
