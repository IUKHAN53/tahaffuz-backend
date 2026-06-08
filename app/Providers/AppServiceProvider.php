<?php

namespace App\Providers;

use App\Services\ElevenLabs;
use App\Services\Pinecone;
use App\Services\Whisper;
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
        // Register Pinecone as singleton (only if configured)
        $this->app->singleton(Pinecone::class, function () {
            return new Pinecone(
                config('services.pinecone.api_key'),
                config('services.pinecone.host'),
                config('services.pinecone.index')
            );
        });

        // Register Whisper as singleton (only if configured)
        $this->app->singleton(Whisper::class, function () {
            return new Whisper(config('services.openai.api_key'));
        });

        // Register ElevenLabs as singleton (only if configured)
        $this->app->singleton(ElevenLabs::class, function () {
            return new ElevenLabs(
                config('services.elevenlabs.api_key'),
                config('services.elevenlabs.voice_id'),
                config('services.elevenlabs.model')
            );
        });
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
