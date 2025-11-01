<div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">{{ __('Node-RED Instances') }}</h1>
        <flux:link href="{{ route('admin.instances.create') }}" variant="primary">
            {{ __('Create Instance') }}
        </flux:link>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Subdomain') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('User') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Plan') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Server') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($instances as $instance)
                    <tr>
                        <td class="px-4 py-3">
                            <flux:link href="{{ route('admin.instances.show', $instance) }}" class="font-medium">
                                {{ $instance->fqdn }}
                            </flux:link>
                        </td>
                        <td class="px-4 py-3">{{ $instance->user->name }}</td>
                        <td class="px-4 py-3">{{ $instance->plan->name }}</td>
                        <td class="px-4 py-3">{{ $instance->server->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                @if($instance->status === 'active') bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200
                                @elseif($instance->status === 'error') bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200
                                @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200
                                @endif">
                                {{ ucfirst($instance->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <flux:link href="{{ route('admin.instances.show', $instance) }}" class="text-sm">
                                    {{ __('View') }}
                                </flux:link>
                                @if(Gate::allows('delete', $instance))
                                    <flux:button 
                                        wire:click="delete({{ $instance->id }})" 
                                        wire:confirm="Are you sure you want to delete this instance? This will remove the Docker container, DNS records, and release server capacity."
                                        size="sm"
                                        variant="danger"
                                    >
                                        {{ __('Delete') }}
                                    </flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-neutral-500">
                            {{ __('No instances found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $instances->links() }}
    </div>
</div>
