<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    <div class="flex items-center justify-between">
        <h1 class="text-3xl font-bold">{{ __('Create Server') }}</h1>
        <flux:link href="{{ route('admin.servers.index') }}" class="text-sm">
            {{ __('Cancel') }}
        </flux:link>
    </div>

    <!-- Progress Steps -->
    <div class="flex items-center gap-2">
        @foreach([1 => __('Server Type'), 2 => __('Location'), 3 => __('Review')] as $step => $label)
            <div class="flex items-center flex-1">
                <div class="flex items-center gap-2 flex-1">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full border-2 transition-all
                        @if($currentStep > $step) bg-green-500 border-green-500 text-white
                        @elseif($currentStep == $step) bg-blue-500 border-blue-500 text-white
                        @else bg-white dark:bg-neutral-800 border-neutral-300 dark:border-neutral-700 text-neutral-400
                        @endif">
                        @if($currentStep > $step)
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        @else
                            <span class="text-sm font-semibold">{{ $step }}</span>
                        @endif
                    </div>
                    <div class="flex-1 @if($currentStep == $step) font-semibold text-blue-600 dark:text-blue-400 @elseif($currentStep > $step) text-neutral-600 dark:text-neutral-400 @else text-neutral-400 @endif">
                        {{ $label }}
                    </div>
                </div>
                @if($step < $totalSteps)
                    <div class="flex-1 h-0.5 mx-2
                        @if($currentStep > $step) bg-green-500
                        @else bg-neutral-200 dark:bg-neutral-700
                        @endif"></div>
                @endif
            </div>
        @endforeach
    </div>

    <!-- Wizard Content -->
    <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 shadow-sm">
        @if($currentStep === 1)
            <!-- Step 1: Choose Server Type -->
            <div class="p-6">
                <h2 class="text-xl font-semibold mb-2">{{ __('Choose Server Type') }}</h2>
                <p class="text-sm text-neutral-500 mb-6">{{ __('Select the server type that best fits your needs.') }}</p>

                <div class="space-y-3">
                    @foreach($serverTypes as $type)
                        @php
                            $cpu = $type['cores'] ?? 0;
                            $memoryGb = $type['memory'] ?? 0;
                            $memoryMb = $memoryGb * 1024;
                            $diskGb = $type['disk'] ?? 0;
                            $typeName = $type['name'] ?? '';
                            $description = $type['description'] ?? '';
                        @endphp
                        <label class="flex items-start gap-4 p-4 rounded-lg border-2 cursor-pointer transition-all
                            @if($serverType == $typeName) border-blue-500 bg-blue-50 dark:bg-blue-900/20
                            @else border-neutral-200 dark:border-neutral-700 hover:border-neutral-300 dark:hover:border-neutral-600
                            @endif">
                            <input type="radio" wire:model="serverType" value="{{ $typeName }}" class="mt-1">
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-2">
                                    <div>
                                        <h3 class="font-semibold text-lg">{{ strtoupper($typeName) }}</h3>
                                        @if($description)
                                            <p class="text-xs text-neutral-500 mt-1">{{ $description }}</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="grid grid-cols-4 gap-4 text-sm text-neutral-600 dark:text-neutral-400">
                                    <div>
                                        <span class="font-medium">{{ __('vCPU') }}:</span> {{ $cpu }}
                                    </div>
                                    <div>
                                        <span class="font-medium">{{ __('Memory') }}:</span> {{ number_format($memoryGb) }} GB
                                    </div>
                                    <div>
                                        <span class="font-medium">{{ __('Storage') }}:</span> {{ number_format($diskGb) }} GB
                                    </div>
                                    <div>
                                        @if($pricing && $serverType == $typeName)
                                            <span class="font-medium">{{ __('Price') }}:</span> €{{ number_format($pricing['price_monthly']['gross'] ?? 0, 2) }}/mo
                                        @else
                                            <span class="font-medium">{{ __('Price') }}:</span> <span class="text-neutral-400">{{ __('Select location') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

        @elseif($currentStep === 2)
            <!-- Step 2: Choose Location -->
            <div class="p-6">
                <h2 class="text-xl font-semibold mb-2">{{ __('Choose Location') }}</h2>
                <p class="text-sm text-neutral-500 mb-6">{{ __('Select the datacenter location for your server.') }}</p>

                @if($serverType)
                    <div class="mb-4 p-4 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-semibold text-sm">{{ __('Selected Server Type') }}: {{ strtoupper($serverType) }}</p>
                                @if($selectedServerType)
                                    <p class="text-xs text-neutral-600 dark:text-neutral-400 mt-1">
                                        {{ number_format(($selectedServerType['memory'] ?? 0)) }} GB RAM • 
                                        {{ $selectedServerType['cores'] ?? 0 }} vCPU • 
                                        {{ number_format(($selectedServerType['disk'] ?? 0)) }} GB Storage
                                    </p>
                                @endif
                            </div>
                            @if($pricing)
                                <div class="text-right">
                                    <p class="font-bold text-lg">€{{ number_format($pricing['price_monthly']['gross'] ?? 0, 2) }}/mo</p>
                                    <p class="text-xs text-neutral-500">{{ __('Monthly') }}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach($locations as $loc)
                        @php
                            $locationPricing = null;
                            if ($serverType) {
                                $locationPricing = app(\App\Services\Hetzner\HetznerClient::class)->getServerPricing($serverType, $loc['name']);
                            }
                        @endphp
                        <label class="flex items-start gap-3 p-4 rounded-lg border-2 cursor-pointer transition-all
                            @if($location == $loc['name']) border-blue-500 bg-blue-50 dark:bg-blue-900/20
                            @else border-neutral-200 dark:border-neutral-700 hover:border-neutral-300 dark:hover:border-neutral-600
                            @endif">
                            <input type="radio" wire:model="location" value="{{ $loc['name'] }}" class="mt-1">
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <h3 class="font-semibold">{{ $loc['city'] ?? $loc['description'] ?? $loc['name'] }}</h3>
                                    @if($locationPricing)
                                        <span class="text-sm font-bold">€{{ number_format($locationPricing['price_monthly']['gross'] ?? 0, 2) }}/mo</span>
                                    @endif
                                </div>
                                <p class="text-xs text-neutral-500">{{ $loc['description'] ?? $loc['name'] }}</p>
                                @if(isset($loc['country']))
                                    <p class="text-xs text-neutral-400 mt-1">{{ $loc['country'] }}</p>
                                @endif
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

        @elseif($currentStep === 3)
            <!-- Step 3: Review & Confirm -->
            <div class="p-6">
                <h2 class="text-xl font-semibold mb-2">{{ __('Review & Confirm') }}</h2>
                <p class="text-sm text-neutral-500 mb-6">{{ __('Please review your configuration before creating the server.') }}</p>

                <div class="space-y-6">
                    <!-- Server Type Summary -->
                    <div class="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4">
                        <h3 class="font-semibold mb-3 text-sm uppercase tracking-wide text-neutral-500">{{ __('Server Type') }}</h3>
                        @if($selectedServerType)
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-neutral-600 dark:text-neutral-400">{{ __('Server Type') }}</span>
                                    <span class="font-medium">{{ strtoupper($selectedServerType['name']) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-600 dark:text-neutral-400">{{ __('vCPU') }}</span>
                                    <span class="font-medium">{{ $selectedServerType['cores'] ?? 0 }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-600 dark:text-neutral-400">{{ __('Memory') }}</span>
                                    <span class="font-medium">{{ number_format($selectedServerType['memory'] ?? 0) }} GB</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-600 dark:text-neutral-400">{{ __('Storage') }}</span>
                                    <span class="font-medium">{{ number_format($selectedServerType['disk'] ?? 0) }} GB</span>
                                </div>
                                @if($pricing)
                                    <div class="flex justify-between pt-2 border-t border-neutral-200 dark:border-neutral-700">
                                        <span class="text-neutral-600 dark:text-neutral-400 font-semibold">{{ __('Monthly Price') }}</span>
                                        <span class="font-bold text-lg">€{{ number_format($pricing['price_monthly']['gross'] ?? 0, 2) }}</span>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <!-- Location Summary -->
                    <div class="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4">
                        <h3 class="font-semibold mb-3 text-sm uppercase tracking-wide text-neutral-500">{{ __('Location') }}</h3>
                        @if($selectedLocation)
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-neutral-600 dark:text-neutral-400">{{ __('City') }}</span>
                                    <span class="font-medium">{{ $selectedLocation['city'] ?? $selectedLocation['description'] ?? $selectedLocation['name'] }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-600 dark:text-neutral-400">{{ __('Region') }}</span>
                                    <span class="font-medium">{{ strtoupper($selectedLocation['name']) }}</span>
                                </div>
                                @if(isset($selectedLocation['country']))
                                    <div class="flex justify-between">
                                        <span class="text-neutral-600 dark:text-neutral-400">{{ __('Country') }}</span>
                                        <span class="font-medium">{{ $selectedLocation['country'] }}</span>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <!-- Server Name -->
                    <div class="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4">
                        <h3 class="font-semibold mb-3 text-sm uppercase tracking-wide text-neutral-500">{{ __('Server Details') }}</h3>
                        <flux:input wire:model="serverName" :label="__('Server Name')" type="text" required />
                        <flux:text class="text-sm text-neutral-500 mt-2">
                            {{ __('This name will be used to identify your server in the dashboard.') }}
                        </flux:text>
                    </div>
                </div>
            </div>
        @endif

        <!-- Navigation Buttons -->
        <div class="flex items-center justify-between p-6 border-t border-neutral-200 dark:border-neutral-700">
            <div>
                @if($currentStep > 1)
                    <flux:button wire:click="previousStep" variant="ghost">
                        {{ __('Previous') }}
                    </flux:button>
                @endif
            </div>
            <div class="flex gap-2">
                @if($currentStep < $totalSteps)
                    <flux:button wire:click="nextStep" variant="primary">
                        {{ __('Next') }}
                    </flux:button>
                @else
                    <flux:button wire:click="save" variant="primary">
                        {{ __('Create Server') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </div>
</div>

