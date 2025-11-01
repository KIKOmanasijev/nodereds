<?php

namespace App\Livewire\Admin\Instances;

use App\Jobs\DeployNodeRedInstanceJob;
use App\Jobs\DeleteNodeRedInstanceJob;
use App\Jobs\ManageNodeRedInstanceJob;
use App\Jobs\SyncNodeRedUsersJob;
use App\Models\NodeRedInstance;
use App\Models\NodeRedUser;
use App\Services\Provisioning\NodeRedDeployer;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public NodeRedInstance $instance;
    public string $activeTab = 'preview';
    
    // Logs
    public bool $liveLogsEnabled = false;
    public string $logs = '';
    public int $logLines = 100;
    
    // Security tab - User management
    public ?int $editingUserId = null;
    public string $username = '';
    public string $password = '';
    public string $permissions = '*';
    public bool $showUserForm = false;

    public function mount(NodeRedInstance $instance): void
    {
        $this->instance = $instance;
        Gate::authorize('view', $instance);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function toggleLiveLogs(): void
    {
        $this->liveLogsEnabled = !$this->liveLogsEnabled;
        if ($this->liveLogsEnabled) {
            $this->refreshLogs();
        }
    }

    public function refreshLogs(): void
    {
        if (!$this->instance->server) {
            $this->logs = 'No server assigned to this instance.';
            return;
        }

        try {
            Gate::authorize('view', $this->instance);
            $deployer = new NodeRedDeployer($this->instance->server);
            $this->logs = $deployer->getLogs($this->instance, $this->logLines);
        } catch (\Exception $e) {
            $this->logs = 'Error fetching logs: ' . $e->getMessage();
        }
    }

    public function startInstance(): void
    {
        Gate::authorize('update', $this->instance);

        if (!$this->instance->server) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Instance has no server assigned.',
            ]);
            return;
        }

        // Dispatch job to start instance asynchronously
        ManageNodeRedInstanceJob::dispatch($this->instance->id, 'start', auth()->id());

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Start job queued. Instance will start shortly.',
        ]);

        $this->instance->refresh();
    }

    public function restartInstance(): void
    {
        Gate::authorize('update', $this->instance);

        if (!$this->instance->server) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Instance has no server assigned.',
            ]);
            return;
        }

        // Dispatch job to restart instance asynchronously
        ManageNodeRedInstanceJob::dispatch($this->instance->id, 'restart', auth()->id());

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Restart job queued. Instance will restart shortly.',
        ]);

        $this->instance->refresh();
    }

    public function stopInstance(): void
    {
        Gate::authorize('update', $this->instance);

        if (!$this->instance->server) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Instance has no server assigned.',
            ]);
            return;
        }

        // Dispatch job to stop instance asynchronously
        ManageNodeRedInstanceJob::dispatch($this->instance->id, 'stop', auth()->id());

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Stop job queued. Instance will stop shortly.',
        ]);

        $this->instance->refresh();
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

    // Security tab methods
    public function showAddUserForm(): void
    {
        Gate::authorize('update', $this->instance);
        $this->resetUserForm();
        $this->showUserForm = true;
    }

    public function editUser(int $userId): void
    {
        Gate::authorize('update', $this->instance);
        $user = NodeRedUser::findOrFail($userId);
        
        if ($user->node_red_instance_id !== $this->instance->id) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Invalid user.',
            ]);
            return;
        }

        $this->editingUserId = $user->id;
        $this->username = $user->username;
        $this->password = '';
        $this->permissions = $user->permissions;
        $this->showUserForm = true;
    }

    public function saveUser(): void
    {
        Gate::authorize('update', $this->instance);

        $rules = [
            'username' => ['required', 'string', 'max:255'],
            'permissions' => ['required', 'string'],
        ];

        if ($this->editingUserId) {
            // Editing existing user
            $rules['username'][] = 'unique:node_red_users,username,' . $this->editingUserId . ',id,node_red_instance_id,' . $this->instance->id;
            if ($this->password) {
                $rules['password'] = ['string', 'min:8'];
            }
        } else {
            // Creating new user
            $rules['username'][] = 'unique:node_red_users,username,NULL,id,node_red_instance_id,' . $this->instance->id;
            $rules['password'] = ['required', 'string', 'min:8'];
        }

        $this->validate($rules);

        $data = [
            'node_red_instance_id' => $this->instance->id,
            'username' => $this->username,
            'permissions' => $this->permissions,
        ];

        if ($this->password) {
            $data['password_hash'] = bcrypt($this->password);
        }

        if ($this->editingUserId) {
            $user = NodeRedUser::findOrFail($this->editingUserId);
            if ($user->node_red_instance_id !== $this->instance->id) {
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => 'Invalid user.',
                ]);
                return;
            }
            $user->update($data);
            $message = 'User updated successfully.';
        } else {
            NodeRedUser::create($data);
            $message = 'User created successfully.';
        }

        // Sync users to settings.js and restart container
        $this->syncUsersAndRestart();

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => $message,
        ]);

        $this->resetUserForm();
        $this->instance->refresh();
    }

    public function deleteUser(int $userId): void
    {
        Gate::authorize('update', $this->instance);
        
        $user = NodeRedUser::findOrFail($userId);
        
        if ($user->node_red_instance_id !== $this->instance->id) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Invalid user.',
            ]);
            return;
        }

        $user->delete();

        // Sync users to settings.js and restart container
        $this->syncUsersAndRestart();

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'User deleted successfully.',
        ]);

        $this->instance->refresh();
    }

    public function cancelUserForm(): void
    {
        $this->resetUserForm();
    }

    private function resetUserForm(): void
    {
        $this->editingUserId = null;
        $this->username = '';
        $this->password = '';
        $this->permissions = '*';
        $this->showUserForm = false;
    }

    private function syncUsersAndRestart(): void
    {
        if (!$this->instance->server) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Cannot sync users: Instance has no server assigned.',
            ]);
            return;
        }

        // Dispatch job to sync users asynchronously
        SyncNodeRedUsersJob::dispatch($this->instance->id, auth()->id());

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'User sync job queued. Changes will be applied shortly.',
        ]);
    }

    public function syncUsers(): void
    {
        Gate::authorize('update', $this->instance);
        $this->syncUsersAndRestart();
    }

    public function delete(): void
    {
        Gate::authorize('delete', $this->instance);

        // Dispatch delete job
        DeleteNodeRedInstanceJob::dispatch($this->instance->id, auth()->id());

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Instance deletion initiated.',
        ]);

        // Redirect to instances index
        $this->redirect(route('admin.instances.index'), navigate: true);
    }

    public function render()
    {
        $this->instance->load([
            'user', 
            'server', 
            'plan', 
            'deployments' => function ($query) {
                $query->with('createdBy')->latest()->limit(10);
            }, 
            'domains', 
            'latestDeployment',
            'nodeRedUsers'
        ]);

        // Check if instance is running
        $isRunning = false;
        if ($this->instance->server) {
            try {
                $deployer = new NodeRedDeployer($this->instance->server);
                $isRunning = $deployer->isRunning($this->instance);
            } catch (\Exception $e) {
                $isRunning = false;
            }
        }

        return view('livewire.admin.instances.show', [
            'instance' => $this->instance,
            'isRunning' => $isRunning,
        ]);
    }
}
