<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold">{{ $instance->fqdn }}</h1>
            <div class="mt-2 flex items-center gap-3">
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium
                    @if($instance->status === 'active') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                    @elseif($instance->status === 'error') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                    @elseif($instance->status === 'deploying') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
                    @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                    @endif">
                    {{ ucfirst($instance->status) }}
                </span>
                @if($instance->deployed_at)
                    <span class="text-sm text-neutral-500">
                        {{ __('Deployed') }} {{ $instance->deployed_at->diffForHumans() }}
                    </span>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-3">
            <flux:link href="{{ route('admin.instances.index') }}" class="text-sm">
                {{ __('Back to Instances') }}
            </flux:link>
            @if(Gate::allows('delete', $instance))
                <flux:button 
                    wire:click="delete" 
                    wire:confirm="Are you sure you want to delete this instance? This will remove the Docker container, DNS records, and release server capacity."
                    variant="danger"
                    size="sm"
                    icon="trash"
                >
                    {{ __('Delete') }}
                </flux:button>
            @endif
        </div>
    </div>

    <!-- Tabs with Sidebar Layout -->
    <div class="flex gap-6">
        <!-- Sidebar Navigation -->
        <div class="w-64 shrink-0">
            <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-4 shadow-sm">
                <nav class="space-y-1">
                    <button 
                        wire:click="setTab('preview')"
                        class="w-full flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors
                            @if($activeTab === 'preview') bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400
                            @else text-neutral-600 hover:bg-neutral-50 dark:text-neutral-400 dark:hover:bg-neutral-900/50
                            @endif">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        {{ __('Instance Preview') }}
                    </button>
                    <button 
                        wire:click="setTab('security')"
                        class="w-full flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors
                            @if($activeTab === 'security') bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400
                            @else text-neutral-600 hover:bg-neutral-50 dark:text-neutral-400 dark:hover:bg-neutral-900/50
                            @endif">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        {{ __('Security') }}
                    </button>
                </nav>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="flex-1 min-w-0">
            @if($activeTab === 'preview')
                <!-- Instance Preview Tab -->
                <div class="flex flex-col gap-6">
                    <div class="grid gap-6 md:grid-cols-2">
                        <!-- Instance Information -->
                        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
                            <h2 class="mb-4 text-lg font-semibold">{{ __('Instance Information') }}</h2>
                            <dl class="mb-6 space-y-3 text-sm">
                                <div class="flex justify-between items-center">
                                    <dt class="text-neutral-500">{{ __('User') }}</dt>
                                    <dd class="font-medium">{{ $instance->user->name }}</dd>
                                </div>
                                <div class="flex justify-between items-center">
                                    <dt class="text-neutral-500">{{ __('Plan') }}</dt>
                                    <dd class="font-medium">{{ $instance->plan->name }}</dd>
                                </div>
                                <div class="flex justify-between items-center">
                                    <dt class="text-neutral-500">{{ __('Memory') }}</dt>
                                    <dd class="font-medium">{{ number_format($instance->memory_mb) }} MB</dd>
                                </div>
                                <div class="flex justify-between items-center">
                                    <dt class="text-neutral-500">{{ __('Storage') }}</dt>
                                    <dd class="font-medium">{{ number_format($instance->storage_gb) }} GB</dd>
                                </div>
                                <div class="flex justify-between items-center">
                                    <dt class="text-neutral-500">{{ __('Slug') }}</dt>
                                    <dd class="font-mono text-xs">{{ $instance->slug }}</dd>
                                </div>
                                @if($instance->server && Gate::allows('super-admin'))
                                    <div class="flex justify-between items-center">
                                        <dt class="text-neutral-500">{{ __('Server') }}</dt>
                                        <dd>
                                            <flux:link href="{{ route('admin.servers.show', $instance->server) }}" class="font-medium">
                                                {{ $instance->server->name }}
                                            </flux:link>
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                            
                            @if($instance->server && Gate::allows('update', $instance))
                                <div class="border-t border-neutral-200 dark:border-neutral-700 pt-4">
                                    <h3 class="mb-3 text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Instance Actions') }}</h3>
                                    <div class="grid grid-cols-2 gap-3">
                                        @if($isRunning)
                                            <flux:button wire:click="stopInstance" variant="danger" size="sm" class="w-full" icon="stop">
                                                {{ __('Stop') }}
                                            </flux:button>
                                        @else
                                            <flux:button wire:click="startInstance" variant="primary" size="sm" class="w-full bg-green-600 hover:bg-green-700" icon="play">
                                                {{ __('Start') }}
                                            </flux:button>
                                        @endif
                                        <flux:button wire:click="restartInstance" variant="primary" size="sm" class="w-full bg-blue-600 hover:bg-blue-700" icon="arrow-path">
                                            {{ __('Restart') }}
                                        </flux:button>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <!-- Access & Connection -->
                        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
                            <h2 class="mb-4 text-lg font-semibold">{{ __('Access') }}</h2>
                            <dl class="space-y-3 text-sm">
                                <div>
                                    <dt class="mb-1 text-neutral-500">{{ __('URL') }}</dt>
                                    <dd>
                                        <flux:link href="https://{{ $instance->fqdn }}" target="_blank" class="font-mono text-sm break-all">
                                            https://{{ $instance->fqdn }}
                                        </flux:link>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="mb-1 text-neutral-500">{{ __('Admin User') }}</dt>
                                    <dd class="font-medium">{{ $instance->admin_user }}</dd>
                                </div>
                                @if($instance->fqdn)
                                    <div>
                                        <dt class="mb-1 text-neutral-500">{{ __('Subdomain') }}</dt>
                                        <dd class="font-mono text-sm">{{ $instance->subdomain }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </div>
                    </div>

                    <!-- Deployment History -->
                    @if($instance->deployments->count() > 0)
                        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
                            <div class="mb-4 flex items-center justify-between">
                                <h2 class="text-lg font-semibold">{{ __('Deployment History') }}</h2>
                                @if(in_array($instance->status, ['error', 'pending']) && Gate::allows('update', $instance))
                                    <flux:button wire:click="retryDeployment" size="sm">
                                        {{ __('Retry Deployment') }}
                                    </flux:button>
                                @endif
                            </div>
                            
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-neutral-200 dark:border-neutral-700">
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('ID') }}</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Triggered By') }}</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('When') }}</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Action') }}</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                        @foreach($instance->deployments as $deployment)
                                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/50">
                                                <td class="px-4 py-3 text-sm font-mono text-neutral-600 dark:text-neutral-400">
                                                    #{{ $deployment->id }}
                                                </td>
                                                <td class="px-4 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                                                    {{ $deployment->createdBy ? $deployment->createdBy->name : __('System') }}
                                                </td>
                                                <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                                                    {{ $deployment->started_at ? $deployment->started_at->format('Y-m-d H:i:s') : $deployment->created_at->format('Y-m-d H:i:s') }}
                                                    <span class="text-xs text-neutral-400 ml-1">
                                                        ({{ $deployment->started_at ? $deployment->started_at->diffForHumans() : $deployment->created_at->diffForHumans() }})
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                                                    {{ __('Deploy') }}
                                                </td>
                                                <td class="px-4 py-3 text-sm">
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                                        @if($deployment->state === 'success') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                                        @elseif($deployment->state === 'failed') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                                                        @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                                                        @endif">
                                                        {{ ucfirst($deployment->state) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <!-- Live Terminal Logs -->
                    @if($instance->server)
                        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
                            <div class="mb-4 flex items-center justify-between">
                                <h2 class="text-lg font-semibold">{{ __('Container Logs') }}</h2>
                                <div class="flex items-center gap-3">
                                    <flux:select wire:model.live="logLines" class="text-sm" size="sm">
                                        <option value="50">50 lines</option>
                                        <option value="100">100 lines</option>
                                        <option value="200">200 lines</option>
                                        <option value="500">500 lines</option>
                                    </flux:select>
                                    <flux:button wire:click="refreshLogs" variant="ghost" size="sm" icon="arrow-path">
                                        {{ __('Refresh') }}
                                    </flux:button>
                                    <flux:button wire:click="toggleLiveLogs" variant="{{ $liveLogsEnabled ? 'primary' : 'outline' }}" size="sm" icon="{{ $liveLogsEnabled ? 'stop' : 'play' }}">
                                        {{ $liveLogsEnabled ? __('Stop Live') : __('Start Live') }}
                                    </flux:button>
                                </div>
                            </div>
                            
                            @if($liveLogsEnabled)
                                <div wire:poll.3s="refreshLogs" class="relative">
                                    <div class="mb-2 flex items-center gap-2 text-xs text-neutral-500">
                                        <span class="inline-flex h-2 w-2 rounded-full bg-green-500 animate-pulse"></span>
                                        {{ __('Live updates every 3 seconds') }}
                                    </div>
                            @endif
                            
                            <div class="relative">
                                <pre 
                                    id="terminal-logs"
                                    class="max-h-[600px] overflow-auto rounded-lg bg-neutral-900 p-4 text-xs font-mono text-neutral-100 dark:bg-neutral-900 dark:text-neutral-100 whitespace-pre-wrap break-words"
                                    x-data="{ 
                                        scrollToBottom() { 
                                            this.$el.scrollTop = this.$el.scrollHeight; 
                                        }
                                    }"
                                    x-init="
                                        if ($wire.liveLogsEnabled) {
                                            $wire.on('logs-updated', () => {
                                                setTimeout(() => scrollToBottom(), 100);
                                            });
                                        }
                                    "
                                    wire:updated="
                                        @if($liveLogsEnabled)
                                            setTimeout(() => {
                                                const el = document.getElementById('terminal-logs');
                                                if (el) el.scrollTop = el.scrollHeight;
                                            }, 100);
                                        @endif
                                    "
                                >{{ $logs ?: __('No logs available. Click "Refresh" to load logs.') }}</pre>
                            </div>
                            
                            @if($liveLogsEnabled)
                                </div>
                            @endif
                            
                            @if($logs)
                                <div class="mt-3 flex items-center justify-end">
                                    <flux:button wire:click="$set('logs', '')" variant="ghost" size="sm">
                                        {{ __('Clear') }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

            @elseif($activeTab === 'security')
                <!-- Security Tab -->
                <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
                    <div class="mb-6 flex items-center justify-between">
                        <h2 class="text-lg font-semibold">{{ __('Node-RED Users') }}</h2>
                        @if(Gate::allows('update', $instance))
                            <div class="flex items-center gap-3">
                                @if($instance->server)
                                    <flux:button wire:click="syncUsers" variant="outline" size="sm" icon="arrow-path">
                                        {{ __('Sync Users') }}
                                    </flux:button>
                                @endif
                                @if(!$showUserForm)
                                    <flux:button wire:click="showAddUserForm" size="sm" icon="plus">
                                        {{ __('Add User') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if($showUserForm)
                        <!-- User Form -->
                        <div class="mb-6 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-900/50 p-4">
                            <h3 class="mb-4 text-sm font-semibold">{{ $editingUserId ? __('Edit User') : __('Add New User') }}</h3>
                            <div class="space-y-4">
                                <flux:input wire:model="username" :label="__('Username')" type="text" required />
                                <flux:input wire:model="password" :label="__('Password')" type="password" :required="!$editingUserId" />
                                <flux:select wire:model="permissions" :label="__('Permissions')" required>
                                    <option value="*">{{ __('Full Access') }} (*)</option>
                                    <option value="read">{{ __('Read Only') }}</option>
                                    <option value="write">{{ __('Read & Write') }}</option>
                                </flux:select>
                                <div class="flex items-center gap-3">
                                    <flux:button wire:click="saveUser" variant="primary" size="sm">
                                        {{ __('Save') }}
                                    </flux:button>
                                    <flux:button wire:click="cancelUserForm" variant="ghost" size="sm">
                                        {{ __('Cancel') }}
                                    </flux:button>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Users List -->
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-neutral-200 dark:border-neutral-700">
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Username') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Permissions') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Created') }}</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-neutral-500">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                <!-- Admin User (from instance) -->
                                @if($instance->admin_user)
                                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/50 bg-blue-50/50 dark:bg-blue-900/10">
                                        <td class="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                            <div class="flex items-center gap-2">
                                                {{ $instance->admin_user }}
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                    {{ __('Admin') }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                *
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                                            {{ $instance->created_at->diffForHumans() }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-right">
                                            <span class="text-xs text-neutral-400">{{ __('Primary Admin') }}</span>
                                        </td>
                                    </tr>
                                @endif
                                
                                <!-- Additional Users -->
                                @foreach($instance->nodeRedUsers as $user)
                                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/50">
                                        <td class="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                            {{ $user->username }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                                @if($user->permissions === '*') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
                                                @else bg-neutral-100 text-neutral-800 dark:bg-neutral-900 dark:text-neutral-200
                                                @endif">
                                                {{ $user->permissions }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">
                                            {{ $user->created_at->diffForHumans() }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-right">
                                            @if(Gate::allows('update', $instance))
                                                <div class="flex items-center justify-end gap-2">
                                                    <flux:button wire:click="editUser({{ $user->id }})" variant="ghost" size="sm" icon="pencil">
                                                        {{ __('Edit') }}
                                                    </flux:button>
                                                    <flux:button wire:click="deleteUser({{ $user->id }})" variant="ghost" size="sm" icon="trash" class="text-red-600 hover:text-red-700 dark:text-red-400">
                                                        {{ __('Delete') }}
                                                    </flux:button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                
                                @if(!$instance->admin_user && $instance->nodeRedUsers->isEmpty())
                                    <tr>
                                        <td colspan="4" class="px-4 py-8 text-center text-sm text-neutral-500">
                                            {{ __('No users found.') }}
                                            @if(Gate::allows('update', $instance) && !$showUserForm)
                                                {{ __('Click "Add User" to create one.') }}
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
