<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One current company per request or queued job (scoped instances are reset between jobs)
        $this->app->scoped(CurrentCompany::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Company abilities come from the user's role. The super admin holds all of them (except the
        // facility user portal) inside a company they opened from the platform area.
        foreach (array_keys(Role::ABILITIES) as $ability) {
            Gate::define($ability, fn (User $user) => $user->isPlatformAdmin()
                ? $ability !== 'use-portal' && CurrentCompany::id() !== null
                : $user->role->can($ability));
        }

        Gate::define('manage-companies', fn (User $user) => $user->isPlatformAdmin());
    }
}
