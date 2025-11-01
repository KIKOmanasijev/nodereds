<?php

use App\Livewire\Admin\Instances\Create;
use App\Livewire\Admin\Instances\Index as InstancesIndex;
use App\Livewire\Admin\Instances\Show as InstancesShow;
use App\Livewire\Admin\Plans\Index as PlansIndex;
use App\Livewire\Admin\Servers\Create as ServersCreate;
use App\Livewire\Admin\Servers\Index as ServersIndex;
use App\Livewire\Admin\Servers\Show as ServersShow;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\TwoFactor;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', Profile::class)->name('profile.edit');
    Route::get('settings/password', Password::class)->name('user-password.edit');
    Route::get('settings/appearance', Appearance::class)->name('appearance.edit');

    Route::get('settings/two-factor', TwoFactor::class)
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');

    // Admin routes
    Route::middleware(['can:super-admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('servers', ServersIndex::class)->name('servers.index');
        Route::get('servers/create', ServersCreate::class)->name('servers.create');
        Route::get('servers/{server}', ServersShow::class)->name('servers.show');
        Route::get('instances', InstancesIndex::class)->name('instances.index');
        Route::get('instances/create', Create::class)->name('instances.create');
        Route::get('instances/{instance}', InstancesShow::class)->name('instances.show');
        Route::get('plans', PlansIndex::class)->name('plans.index');
    });
});
