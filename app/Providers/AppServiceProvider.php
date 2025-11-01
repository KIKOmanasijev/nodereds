<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register super admin gate
        \Illuminate\Support\Facades\Gate::define('super-admin', function ($user) {
            return $user->isSuperAdmin();
        });
    }
}
