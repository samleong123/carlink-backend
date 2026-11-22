<?php
namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->bind(\App\Services\Broker\BrokerPublisher::class, \App\Services\Broker\AmqpPublisher::class); }
    public function boot(): void
    {
        RateLimiter::for('device-commands', fn ($request) => Limit::perMinute(60)->by($request->route('device') ?: $request->ip()));
        RateLimiter::for('device-dtc-ai', fn ($request) => Limit::perMinute(10)->by($request->route('device') ?: $request->ip()));
    }
}
