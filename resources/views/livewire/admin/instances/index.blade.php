<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">{{ __('Node-RED Instances') }}</h1>
        <flux:link href="{{ route('admin.instances.create') }}" variant="primary">
            {{ __('Create Instance') }}
        </flux:link>
    </div>

    @if($instances->count() > 0)
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($instances as $instance)
                <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 shadow-sm transition-shadow hover:shadow-md">
                    <!-- Logo and Header -->
                    <div class="flex items-center gap-4 border-b border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-900/50 p-4">
                        <!-- Node-RED Logo -->
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-white dark:bg-neutral-800 p-2">
                            <img src="{{ asset('node-red.png') }}" alt="Node-RED" class="h-full w-full object-contain" />
                        </div>
                        
                        <!-- Title and Status -->
                        <div class="min-w-0 flex-1">
                            <h3 class="truncate text-base font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ $instance->subdomain }}
                            </h3>
                            <div class="mt-1">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    @if($instance->status === 'active') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                    @elseif($instance->status === 'error') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                                    @elseif($instance->status === 'deploying') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
                                    @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                                    @endif">
                                    {{ ucfirst($instance->status) }}
                                </span>
                            </div>
                        </div>
                        
                        <!-- Plan (right side) -->
                        <div class="shrink-0 text-right">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                {{ $instance->plan->name }}
                            </span>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="px-4 pt-4 pb-0">
                        <!-- User -->
                        <div class="mb-4">
                            <dt class="mb-1 text-xs font-medium text-neutral-500">{{ __('User') }}</dt>
                            <dd class="text-sm text-neutral-900 dark:text-neutral-100">{{ $instance->user->name }}</dd>
                        </div>

                        <!-- Server -->
                        @if($instance->server)
                            <div class="mb-4">
                                <dt class="mb-1 text-xs font-medium text-neutral-500">{{ __('Server') }}</dt>
                                <dd class="text-sm text-neutral-900 dark:text-neutral-100">{{ $instance->server->name }}</dd>
                            </div>
                        @endif

                        <!-- Instance URL -->
                        @if($instance->fqdn)
                            <div class="mb-4">
                                <dt class="mb-1 text-xs font-medium text-neutral-500">{{ __('Instance URL') }}</dt>
                                <dd class="text-sm text-neutral-900 dark:text-neutral-100">
                                    <flux:link href="https://{{ $instance->fqdn }}" target="_blank" class="font-mono text-xs break-all">
                                        https://{{ $instance->fqdn }}
                                    </flux:link>
                                </dd>
                            </div>
                        @endif
                    </div>

                    <!-- Actions Grid (1/2 width) -->
                    <div class="border-t border-neutral-200 dark:border-neutral-700">
                        <div class="grid grid-cols-2">
                            <flux:link href="{{ route('admin.instances.show', $instance) }}" variant="primary" size="sm" class="w-full text-center rounded-none border-r border-neutral-200 dark:border-neutral-700 py-2 no-underline" icon="pencil">
                                {{ __('Edit') }}
                            </flux:link>
                            @if($instance->status === 'active' && $instance->fqdn)
                                <flux:link href="https://{{ $instance->fqdn }}" target="_blank" variant="outline" size="sm" class="w-full text-center rounded-none py-2 no-underline" icon="arrow-top-right-on-square">
                                    {{ __('Visit') }}
                                </flux:link>
                            @else
                                <flux:button disabled variant="ghost" size="sm" class="w-full rounded-none py-2" icon="arrow-top-right-on-square">
                                    {{ __('Visit') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-12 text-center">
            <p class="text-neutral-500">{{ __('No instances found.') }}</p>
            <p class="mt-2 text-sm text-neutral-400">{{ __('Create your first Node-RED instance to get started.') }}</p>
        </div>
    @endif

    @if($instances->hasPages())
        <div class="mt-4">
            {{ $instances->links() }}
        </div>
    @endif
</div>
