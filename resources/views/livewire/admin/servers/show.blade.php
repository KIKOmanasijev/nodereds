<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold">{{ $server->name }}</h1>
            <div class="mt-2 flex items-center gap-3">
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium
                    @if($server->status === 'active') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                    @elseif($server->status === 'error') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                    @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                    @endif">
                    {{ ucfirst($server->status) }}
                </span>
                <span class="text-sm text-neutral-500">{{ $server->public_ip }}</span>
                @if($server->private_ip)
                <span class="text-sm text-neutral-500">/ {{ $server->private_ip }}</span>
                @endif
            </div>
        </div>
        <flux:link href="{{ route('admin.servers.index') }}" class="text-sm">
            {{ __('Back to Servers') }}
        </flux:link>
    </div>

    <!-- Key Metrics Cards -->
    <div class="grid gap-4 md:grid-cols-4">
        <!-- Memory Usage Card -->
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-medium text-neutral-500">{{ __('Memory') }}</h3>
                <svg class="h-5 w-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
            </div>
            <div class="mb-2">
                <p class="text-2xl font-bold">{{ number_format($allocatedMemoryMb) }} / {{ number_format($server->ram_mb_total) }}</p>
                <p class="text-xs text-neutral-500">{{ __('MB') }} {{ __('allocated') }}</p>
            </div>
            <div class="mb-1 flex justify-between text-xs text-neutral-500">
                <span>{{ __('Allocated') }}</span>
                <span>{{ number_format(($allocatedMemoryMb / $server->ram_mb_total) * 100, 1) }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                <div class="h-full bg-blue-500 transition-all" style="width: {{ min(100, ($allocatedMemoryMb / $server->ram_mb_total) * 100) }}%"></div>
            </div>
            <p class="mt-2 text-xs text-green-600 dark:text-green-400">{{ number_format($server->available_memory_mb) }} MB {{ __('available') }}</p>
        </div>

        <!-- Storage Usage Card -->
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-medium text-neutral-500">{{ __('Storage') }}</h3>
                <svg class="h-5 w-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                </svg>
            </div>
            <div class="mb-2">
                <p class="text-2xl font-bold">{{ number_format($allocatedDiskGb) }} / {{ number_format($server->disk_gb_total) }}</p>
                <p class="text-xs text-neutral-500">{{ __('GB') }} {{ __('allocated') }}</p>
            </div>
            <div class="mb-1 flex justify-between text-xs text-neutral-500">
                <span>{{ __('Allocated') }}</span>
                <span>{{ number_format(($allocatedDiskGb / $server->disk_gb_total) * 100, 1) }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                <div class="h-full bg-blue-500 transition-all" style="width: {{ min(100, ($allocatedDiskGb / $server->disk_gb_total) * 100) }}%"></div>
            </div>
            <p class="mt-2 text-xs text-green-600 dark:text-green-400">{{ number_format($server->available_disk_gb) }} GB {{ __('available') }}</p>
        </div>

        <!-- Server Cost Card (Super Admin Only) -->
        @if(Gate::allows('super-admin'))
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-medium text-neutral-500">{{ __('Monthly Cost') }}</h3>
                <svg class="h-5 w-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <div class="mb-2">
                @if($monthlyCostCents !== null)
                <p class="text-2xl font-bold">€{{ number_format($monthlyCostCents / 100, 2) }}</p>
                @else
                <p class="text-lg font-medium text-neutral-400">{{ __('N/A') }}</p>
                @endif
                <p class="text-xs text-neutral-500">{{ __('per month') }}</p>
            </div>
            <p class="text-xs text-neutral-400">{{ __('Server hosting cost') }}</p>
        </div>

        <!-- Total Revenue Card -->
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-medium text-neutral-500">{{ __('Monthly Revenue') }}</h3>
                <svg class="h-5 w-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
            </div>
            <div class="mb-2">
                <p class="text-2xl font-bold text-green-600 dark:text-green-400">${{ number_format($totalMonthlyRevenueCents / 100, 2) }}</p>
                <p class="text-xs text-neutral-500">{{ __('per month') }}</p>
            </div>
            <p class="text-xs text-neutral-400">{{ $server->nodeRedInstances->where('status', 'active')->count() }} {{ __('active instances') }}</p>
        </div>
        @else
        <!-- Instances Count Card (for non-super-admins) -->
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6 shadow-sm">
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-medium text-neutral-500">{{ __('Instances') }}</h3>
                <svg class="h-5 w-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
            </div>
            <div class="mb-2">
                <p class="text-2xl font-bold">{{ $server->nodeRedInstances->count() }}</p>
                <p class="text-xs text-neutral-500">{{ __('total') }}</p>
            </div>
            <p class="text-xs text-green-600 dark:text-green-400">{{ $server->nodeRedInstances->where('status', 'active')->count() }} {{ __('active') }}</p>
        </div>
        @endif
    </div>

    <!-- Three Column Layout: Hardware Specs, Server Actions, Instances -->
    <div class="grid gap-6 md:grid-cols-3">

        <!-- Node-RED Instances -->
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 shadow-sm">
            <div class="border-b border-neutral-200 dark:border-neutral-700 px-5 py-3">
                <h2 class="text-base font-semibold">{{ __('Node-RED Instances') }}</h2>
                <p class="mt-1 text-xs text-neutral-500">{{ $server->nodeRedInstances->count() }} {{ __('instance(s)') }}</p>
            </div>
            <div class="divide-y divide-neutral-200 dark:divide-neutral-700 max-h-[500px] overflow-y-auto">
                @forelse($server->nodeRedInstances as $instance)
                <div class="px-5 py-3 transition-colors hover:bg-neutral-50 dark:hover:bg-neutral-900/50">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <flux:link href="{{ route('admin.instances.show', $instance) }}" class="font-medium text-sm hover:text-blue-600 dark:hover:text-blue-400 truncate">
                                    {{ $instance->fqdn }}
                                </flux:link>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium shrink-0
                                @if($instance->status === 'active') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                @elseif($instance->status === 'error') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                                @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                                @endif">
                                    {{ ucfirst($instance->status) }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs text-neutral-500">
                                <span>{{ $instance->user->name }}</span>
                                <span>•</span>
                                <span>{{ $instance->plan->name }}</span>
                            </div>
                            <div class="mt-1 text-xs text-neutral-400">
                                {{ number_format($instance->memory_mb) }} MB / {{ number_format($instance->storage_gb) }} GB
                            </div>
                        </div>
                        <div class="shrink-0">
                            <flux:link href="{{ route('admin.instances.show', $instance) }}" variant="ghost" size="sm">
                                {{ __('View') }}
                            </flux:link>
                        </div>
                    </div>
                </div>
                @empty
                <div class="px-5 py-8 text-center">
                    <p class="text-sm text-neutral-500">{{ __('No instances') }}</p>
                    <p class="mt-1 text-xs text-neutral-400">{{ __('Create a new instance') }}</p>
                </div>
                @endforelse
            </div>
        </div>
        <!-- Hardware Specifications -->
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-5 shadow-sm">
            <h2 class="mb-4 text-base font-semibold">{{ __('Hardware Specifications') }}</h2>
            <dl class="space-y-4">
                <div>
                    <dt class="text-xs font-medium text-neutral-400 uppercase tracking-wide mb-1">{{ __('Server Type') }}</dt>
                    <dd class="text-xl font-bold text-neutral-900 dark:text-neutral-100">{{ strtoupper($server->server_type ?? '-') }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-neutral-400 uppercase tracking-wide mb-1">{{ __('Region') }}</dt>
                    <dd class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        @if($regionInfo)
                            {{ $regionInfo['city'] ?? $regionInfo['description'] ?? $server->region }}
                            <span class="text-sm font-normal text-neutral-500 ml-1">({{ $server->region }})</span>
                        @else
                            {{ strtoupper($server->region) }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-neutral-400 uppercase tracking-wide mb-1">{{ __('Provider ID') }}</dt>
                    <dd class="text-lg font-mono font-semibold text-neutral-700 dark:text-neutral-300 break-all">{{ $server->provider_id }}</dd>
                </div>
                @if($server->provisioned_at)
                <div class="pt-3 border-t border-neutral-200 dark:border-neutral-700">
                    <dt class="text-xs font-medium text-neutral-400 uppercase tracking-wide mb-1">{{ __('Provisioned') }}</dt>
                    <dd class="text-sm text-neutral-500">{{ $server->provisioned_at->diffForHumans() }}</dd>
                </div>
                @endif
            </dl>
        </div>

        <!-- Server Actions -->
        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-5 shadow-sm">
            <h2 class="mb-3 text-base font-semibold">{{ __('Server Actions') }}</h2>
            <div class="space-y-3">
                <flux:button wire:click="checkServerStatus" variant="outline" class="w-full justify-start" icon="arrow-path" size="sm">
                    {{ __('Check Server Status') }}
                </flux:button>
                @if($server->status === 'active')
                <flux:button wire:click="restartTraefik" variant="outline" class="w-full justify-start" icon="arrow-path" size="sm">
                    {{ __('Restart Traefik') }}
                </flux:button>
                <flux:button wire:click="restartServer" variant="outline" class="w-full justify-start" icon="arrow-path" size="sm">
                    {{ __('Restart Server') }}
                </flux:button>
                @endif

                <div class="border-t border-neutral-200 dark:border-neutral-700 pt-3 mt-3">
                    <flux:button wire:click="deleteServer" wire:confirm="Are you sure you want to delete this server? This will also delete it from Hetzner Cloud. This action cannot be undone." variant="danger" class="w-full justify-start" icon="trash" size="sm">
                        {{ __('Delete Server') }}
                    </flux:button>
                </div>

                <div class="rounded-lg bg-neutral-50 dark:bg-neutral-900/50 p-3">
                    <h3 class="mb-2 text-xs font-medium">{{ __('Server Information') }}</h3>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between">
                            <dt class="text-neutral-500">{{ __('Status') }}</dt>
                            <dd class="font-medium">{{ ucfirst($server->status) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-neutral-500">{{ __('Instances') }}</dt>
                            <dd class="font-medium">{{ $server->nodeRedInstances->count() }} {{ __('running') }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>