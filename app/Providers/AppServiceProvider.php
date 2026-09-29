<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
        // Verwaltung: Stammdaten, Preise, Abrechnungen, Benutzer, Einstellungen.
        Gate::define('manage', fn (User $user) => $user->role === Role::Admin);

        // Abrechnungen, Preise und Rechnungen ansehen: Verwaltung und Lesezugriff, nicht der Hausmeister.
        Gate::define('view-finance', fn (User $user) => in_array($user->role, [Role::Admin, Role::Viewer], true));

        // Ablesungen erfassen: Verwaltung und Hausmeister.
        Gate::define('record-readings', fn (User $user) => in_array($user->role, [Role::Admin, Role::Caretaker], true));
    }
}
