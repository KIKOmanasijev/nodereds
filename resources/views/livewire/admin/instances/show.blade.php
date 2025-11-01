<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
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
        <flux:link href="{{ route('admin.instances.index') }}" class="text-sm">
            {{ __('Back to Instances') }}
        </flux:link>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <!-- Instance Information -->
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">{{ __('Instance Information') }}</h2>
            <dl class="space-y-3 text-sm">
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
                @if($instance->server)
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
    @if($instance->latestDeployment)
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold">{{ __('Latest Deployment') }}</h2>
                @if(in_array($instance->status, ['error', 'pending']) && Gate::allows('update', $instance))
                    <flux:button wire:click="retryDeployment" size="sm">
                        {{ __('Retry Deployment') }}
                    </flux:button>
                @endif
            </div>
            
            <div class="mb-4 flex items-center gap-4 text-sm">
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                    @if($instance->latestDeployment->state === 'success') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                    @elseif($instance->latestDeployment->state === 'failed') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                    @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                    @endif">
                    {{ ucfirst($instance->latestDeployment->state) }}
                </span>
                <span class="text-neutral-500">
                    {{ $instance->latestDeployment->created_at->diffForHumans() }}
                </span>
                @if($instance->latestDeployment->metadata && isset($instance->latestDeployment->metadata['attempt']))
                    <span class="text-xs text-neutral-400">
                        (Attempt {{ $instance->latestDeployment->metadata['attempt'] }}/{{ $instance->latestDeployment->metadata['max_attempts'] ?? 3 }})
                    </span>
                @endif
            </div>
            
            @if($instance->latestDeployment->reason)
                <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-900/20 p-3">
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $instance->latestDeployment->reason }}</p>
                </div>
            @endif
            
            @if($instance->latestDeployment->logs)
                <div>
                    <h3 class="mb-2 text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __('Deployment Logs') }}</h3>
                    <pre class="max-h-96 overflow-auto rounded-lg bg-neutral-900 p-4 text-xs font-mono text-neutral-100 dark:bg-neutral-900 dark:text-neutral-100">{{ $instance->latestDeployment->logs }}</pre>
                </div>
            @endif
        </div>
    @endif

    <!-- Recent Deployments -->
    @if($instance->deployments->count() > 1)
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold">{{ __('Deployment History') }}</h2>
            <div class="space-y-2">
                @foreach($instance->deployments->take(5) as $deployment)
                    <div class="flex items-center justify-between rounded-lg border border-neutral-200 dark:border-neutral-700 p-3">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                @if($deployment->state === 'success') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                @elseif($deployment->state === 'failed') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                                @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                                @endif">
                                {{ ucfirst($deployment->state) }}
                            </span>
                            <span class="text-sm text-neutral-500">{{ $deployment->created_at->diffForHumans() }}</span>
                        </div>
                        @if($deployment->completed_at)
                            <span class="text-xs text-neutral-400">
                                {{ $deployment->started_at->diffForHumans($deployment->completed_at, true) }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
