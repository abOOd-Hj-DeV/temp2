<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\ForceJsonResponse;
use App\Jobs\CleanupUnverifiedUsersJob;
use App\Jobs\PruneScheduledDeletionsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only backend: every api/* request negotiates JSON so auth
        // failures return 401 JSON instead of a redirect to a web login.
        $middleware->api(prepend: [ForceJsonResponse::class]);
        $middleware->alias([
            'role' => CheckRole::class,
            'status' => CheckUserStatus::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Remove accounts that never completed OTP verification within 24h.
        $schedule->job(new CleanupUnverifiedUsersJob)->hourly();
        // Permanently purge accounts whose deletion grace period elapsed.
        $schedule->job(new PruneScheduledDeletionsJob)->daily();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
