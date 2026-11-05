<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Menu Pengguna, API key Claude, dan menghubungkan Google Sheets.
        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin());
        // Mic ke Laptop: hanya satu akun (owner), bukan semua Super Admin.
        Gate::define('mic', fn (User $user) => $user->bolehMic());
        Gate::define('rumah', fn (User $user) => $user->bolehRumah());
    }
}
