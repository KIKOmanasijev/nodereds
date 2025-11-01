<div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">{{ __('Plans') }}</h1>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Name') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Memory') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Storage') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Price') }}</th>
                    <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse($plans as $plan)
                    <tr>
                        <td class="px-4 py-3">{{ $plan->name }}</td>
                        <td class="px-4 py-3">{{ $plan->memory_mb }} MB</td>
                        <td class="px-4 py-3">{{ $plan->storage_gb }} GB</td>
                        <td class="px-4 py-3">${{ number_format($plan->monthly_price_cents / 100, 2) }}/mo</td>
                        <td class="px-4 py-3">
                            @if($plan->is_active)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">
                                    {{ __('Active') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-900 dark:text-gray-200">
                                    {{ __('Inactive') }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-500">
                            {{ __('No plans found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $plans->links() }}
    </div>
</div>
