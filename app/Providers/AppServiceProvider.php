<?php

namespace App\Providers;

use App\Services\AuditTrailService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
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
        Vite::prefetch(concurrency: 3);
        RateLimiter::for('workflow-ai', fn (Request $request): Limit => Limit::perMinute(5)->by((string) ($request->user()?->id ?? $request->ip())));

        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen(
                "eloquent.{$event}: *",
                fn (string $eventName, array $payload) => app(AuditTrailService::class)->record($eventName, $payload),
            );
        }
    }
}
