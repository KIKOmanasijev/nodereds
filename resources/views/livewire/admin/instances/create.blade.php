<div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
    <div class="flex items-center justify-between">
        <h1 class="text-3xl font-bold">{{ __('Create Node-RED Instance') }}</h1>
        <flux:link href="{{ route('admin.instances.index') }}" class="text-sm">
            {{ __('Cancel') }}
        </flux:link>
    </div>

    <!-- Progress Steps -->
    <div class="flex items-center gap-2">
        @foreach([1 => __('Plan'), 2 => __('Details'), 3 => __('Review')] as $step => $label)
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
            <!-- Step 1: Choose Plan -->
            <div class="p-6">
                <h2 class="text-xl font-semibold mb-2">{{ __('Choose Instance Plan') }}</h2>
                <p class="text-sm text-neutral-500 mb-6">{{ __('Select the plan that best fits your Node-RED instance requirements.') }}</p>

                <div class="space-y-3">
                    @foreach($plans as $plan)
                        <label class="flex items-start gap-4 p-4 rounded-lg border-2 cursor-pointer transition-all
                            @if($plan_id == $plan->id) border-blue-500 bg-blue-50 dark:bg-blue-900/20
                            @else border-neutral-200 dark:border-neutral-700 hover:border-neutral-300 dark:hover:border-neutral-600
                            @endif">
                            <input type="radio" wire:model="plan_id" value="{{ $plan->id }}" class="mt-1">
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-semibold text-lg">{{ $plan->name }}</h3>
                                    @if($plan->monthly_price_cents)
                                        <span class="text-lg font-bold">${{ number_format($plan->monthly_price_cents / 100, 2) }}/mo</span>
                                    @endif
                                </div>
                                <div class="grid grid-cols-3 gap-4 text-sm text-neutral-600 dark:text-neutral-400">
                                    <div>
                                        <span class="font-medium">{{ __('Memory') }}:</span> {{ number_format($plan->memory_mb) }} MB
                                    </div>
                                    <div>
                                        <span class="font-medium">{{ __('Storage') }}:</span> {{ number_format($plan->storage_gb) }} GB
                                    </div>
                                    @if($plan->monthly_price_cents)
                                        <div>
                                            <span class="font-medium">{{ __('Price') }}:</span> ${{ number_format($plan->monthly_price_cents / 100, 2) }}/mo
                                        </div>
                                    @endif
                                </div>
                                @if($plan->description)
                                    <p class="mt-2 text-sm text-neutral-500">{{ $plan->description }}</p>
                                @endif
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

        @elseif($currentStep === 2)
            <!-- Step 2: Basic Details -->
            <div class="p-6">
                <h2 class="text-xl font-semibold mb-2">{{ __('Instance Details') }}</h2>
                <p class="text-sm text-neutral-500 mb-6">{{ __('Configure your Node-RED instance settings.') }}</p>

                <div class="space-y-6">
                    <flux:select wire:model="user_id" :label="__('User')" required>
                        <option value="0">{{ __('Select a user') }}</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="subdomain" :label="__('Subdomain')" type="text" required placeholder="my-instance" />
                    <flux:text class="text-sm text-neutral-500">
                        {{ __('Will be accessible at') }}: <strong>{{ $subdomain ? (str_contains($subdomain, '.') ? explode('.', $subdomain)[0] : $subdomain) . '.' . config('provisioning.dns.base_domain', 'nodereds.com') : 'subdomain.nodereds.com' }}</strong>
                    </flux:text>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="admin_user" :label="__('Admin Username')" type="text" required />
                        <flux:input wire:model="admin_password" :label="__('Admin Password')" type="password" required />
                    </div>

                    <flux:select wire:model="server_id" :label="__('Server (Optional)')">
                        <option value="">{{ __('Auto-select server') }}</option>
                        @foreach($servers as $server)
                            <option value="{{ $server->id }}">
                                {{ $server->name }} 
                                ({{ $server->region }} - {{ number_format($server->allocated_memory_mb) }}/{{ number_format($server->ram_mb_total) }}MB, 
                                {{ number_format($server->allocated_disk_gb) }}/{{ number_format($server->disk_gb_total) }}GB)
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:text class="text-sm text-neutral-500">
                        {{ __('If no server is selected, the app will automatically choose an available server with sufficient capacity.') }}
                    </flux:text>
                </div>
            </div>

        @elseif($currentStep === 3)
            <!-- Step 3: Review & Confirm -->
            <div class="p-6">
                <h2 class="text-xl font-semibold mb-2">{{ __('Review & Confirm') }}</h2>
                <p class="text-sm text-neutral-500 mb-6">{{ __('Please review your configuration before creating the instance.') }}</p>

                <div class="space-y-6">
                    <!-- Plan Summary -->
                    <div class="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4">
                        <h3 class="font-semibold mb-3 text-sm uppercase tracking-wide text-neutral-500">{{ __('Plan') }}</h3>
                        @if($selectedPlan)
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-neutral-600 dark:text-neutral-400">{{ __('Plan Name') }}</span>
                                    <span class="font-medium">{{ $selectedPlan->name }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-600 dark:text-neutral-400">{{ __('Memory') }}</span>
                                    <span class="font-medium">{{ number_format($selectedPlan->memory_mb) }} MB</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-600 dark:text-neutral-400">{{ __('Storage') }}</span>
                                    <span class="font-medium">{{ number_format($selectedPlan->storage_gb) }} GB</span>
                                </div>
                                @if($selectedPlan->monthly_price_cents)
                                    <div class="flex justify-between">
                                        <span class="text-neutral-600 dark:text-neutral-400">{{ __('Monthly Price') }}</span>
                                        <span class="font-medium">${{ number_format($selectedPlan->monthly_price_cents / 100, 2) }}</span>
                                    </div>
                                @endif
                            </div>
                        @else
                            <p class="text-neutral-400">{{ __('No plan selected') }}</p>
                        @endif
                    </div>

                    <!-- Instance Details -->
                    <div class="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4">
                        <h3 class="font-semibold mb-3 text-sm uppercase tracking-wide text-neutral-500">{{ __('Instance Details') }}</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-neutral-600 dark:text-neutral-400">{{ __('User') }}</span>
                                <span class="font-medium">{{ $selectedUser ? $selectedUser->name . ' (' . $selectedUser->email . ')' : __('Not selected') }}</span>
                            </div>
                            @php
                                $cleanSubdomain = $subdomain;
                                if ($subdomain && str_contains($subdomain, '.')) {
                                    $cleanSubdomain = explode('.', $subdomain)[0];
                                }
                                $cleanSubdomain = $cleanSubdomain ? \Illuminate\Support\Str::slug($cleanSubdomain) : '';
                            @endphp
                            <div class="flex justify-between">
                                <span class="text-neutral-600 dark:text-neutral-400">{{ __('Subdomain') }}</span>
                                <span class="font-medium">{{ $cleanSubdomain ?: __('Not set') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-neutral-600 dark:text-neutral-400">{{ __('FQDN') }}</span>
                                <span class="font-medium">{{ $cleanSubdomain ? $cleanSubdomain . '.' . config('provisioning.dns.base_domain', 'nodereds.com') : '-' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-neutral-600 dark:text-neutral-400">{{ __('Admin Username') }}</span>
                                <span class="font-medium">{{ $admin_user ?: __('Not set') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-neutral-600 dark:text-neutral-400">{{ __('Server') }}</span>
                                <span class="font-medium">
                                    @if($selectedServer)
                                        {{ $selectedServer->name }} ({{ $selectedServer->region }})
                                    @else
                                        {{ __('Auto-select') }}
                                    @endif
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Navigation Buttons -->
        <div class="border-t border-neutral-200 dark:border-neutral-700 px-6 py-4 flex items-center justify-between">
            <div>
                @if($currentStep > 1)
                    <flux:button wire:click="previousStep" variant="ghost" icon="arrow-left">
                        {{ __('Back') }}
                    </flux:button>
                @endif
            </div>
            <div class="flex gap-3">
                <flux:link href="{{ route('admin.instances.index') }}" variant="ghost">
                    {{ __('Cancel') }}
                </flux:link>
                @if($currentStep < $totalSteps)
                    <flux:button wire:click="nextStep" variant="primary" icon-right="arrow-right">
                        {{ __('Next') }}
                    </flux:button>
                @else
                    <flux:button wire:click="save" variant="primary" icon="check">
                        {{ __('Create Instance') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </div>

    @if(session('message'))
        <flux:text class="text-green-600 dark:text-green-400">{{ session('message') }}</flux:text>
    @endif
</div>
