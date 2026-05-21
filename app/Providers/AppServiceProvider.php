<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Throttle the public chat API by device, not IP — a whole clinic can
        // share one NAT address, so IP keying would punish honest users.
        RateLimiter::for('chat', function (Request $request) {
            $key = (string) ($request->input('device_id') ?: $request->ip());

            return Limit::perMinute(20)->by('chat:'.$key);
        });

        RateLimiter::for('chat-read', function (Request $request) {
            $key = (string) ($request->input('device_id') ?: $request->ip());

            return Limit::perMinute(90)->by('chat-read:'.$key);
        });
    }
}
