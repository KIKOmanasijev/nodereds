<div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">{{ __('Servers') }}</h1>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Name') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('IP Address') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Region') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Memory') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($servers as $server)
                    <tr>
                        <td class="px-4 py-3">{{ $server->name }}</td>
                        <td class="px-4 py-3">{{ $server->public_ip ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $server->region }}</td>
                        <td class="px-4 py-3">
                            {{ number_format($server->available_memory_mb) }} MB / {{ number_format($server->ram_mb_total) }} MB
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                @if($server->status === 'active') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                @elseif($server->status === 'error') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                                @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                                @endif">
                                {{ ucfirst($server->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <flux:link href="{{ route('admin.servers.show', $server) }}" class="text-sm">
                                    {{ __('View') }}
                                </flux:link>
                                <flux:button wire:click="checkServerStatus({{ $server->id }})" variant="ghost" size="sm" icon="arrow-path" class="text-xs">
                                    {{ __('Check Status') }}
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-neutral-500">
                            {{ __('No servers found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $servers->links() }}
    </div>
</div>
