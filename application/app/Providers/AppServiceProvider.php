<?php

namespace App\Providers;

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\ManageUsers;
use App\Http\Middleware\RequireMfa;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
        Livewire::addPersistentMiddleware([
            ActiveAccount::class,
            RequireMfa::class,
            ManageUsers::class,
        ]);
        RateLimiter::for('login', fn ($r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by($r->ip()),
        ]);
        RateLimiter::for('sensitive', fn ($r) => Limit::perMinute(5)->by($r->user()?->id ?? $r->ip()));
        RateLimiter::for('invitation', function ($r) {
            $invitation = $r->route('invitation');

            return [
                Limit::perMinute(10)->by('invite-ip:'.$r->ip()),
                Limit::perMinute(5)->by('invite-id:'.(is_object($invitation) ? $invitation->id : $invitation)),
            ];
        });
    }
}
