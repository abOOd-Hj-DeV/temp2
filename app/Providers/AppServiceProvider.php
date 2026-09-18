<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(RepositoryServiceProvider::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Global ceiling applied to every api/* route (bootstrap/app.php throttleApi()).
        // Route-level throttles remain stricter for sensitive writes.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('sakina.api_rate_limit_per_minute', 120))
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });

        // Full personal-data export is expensive and sensitive: a handful per hour.
        RateLimiter::for('export', function (Request $request) {
            return Limit::perHour((int) config('sakina.export_rate_limit_per_hour', 3))
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });
    }
}
