<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ThrottleRequests;
use App\RateLimiting\Contracts\RateLimitStore;
use App\RateLimiting\SlidingWindowLimiter;
use App\RateLimiting\Stores\ApcuStore;
use App\RateLimiting\Stores\ArrayStore;
use Illuminate\Support\ServiceProvider;

final class RateLimitingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SINGLETON is load-bearing: under Octane/Swoole the store instance is
        // reused across requests in the worker, which is how in-memory counters
        // survive at all. Bound as transient, the array would reset every request.
        $this->app->singleton(RateLimitStore::class, function () {
            // Prefer shared memory when available (works under PHP-FPM too),
            // fall back to a process-local array for local/dev or single-worker runtimes.
            return \function_exists('apcu_fetch') && \ini_get('apc.enabled')
                ? new ApcuStore()
                : new ArrayStore();
        });

        $this->app->singleton(SlidingWindowLimiter::class, function ($app) {
            return new SlidingWindowLimiter($app->make(RateLimitStore::class));
        });

        $this->app->singleton(ThrottleRequests::class, function ($app) {
            return new ThrottleRequests(
                $app->make(SlidingWindowLimiter::class),
                $app['config']->get('rate-limiting'),
            );
        });
    }
}
