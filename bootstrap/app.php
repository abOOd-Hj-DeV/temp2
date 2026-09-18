<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\EnsureTherapistApproved;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LimitJsonBodySize;
use App\Http\Middleware\SecurityHeaders;
use App\Jobs\CleanupUnverifiedUsersJob;
use App\Jobs\EscalateStaleRedFlagsJob;
use App\Jobs\PruneScheduledDeletionsJob;
use App\Jobs\SendSessionRemindersJob;
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
        $middleware->api(prepend: [SecurityHeaders::class, ForceJsonResponse::class, LimitJsonBodySize::class]);
        // Global per-user/IP ceiling on every api/* route (limiter defined in AppServiceProvider).
        $middleware->throttleApi();
        $middleware->alias([
            'role' => CheckRole::class,
            'status' => CheckUserStatus::class,
            'therapist.approved' => EnsureTherapistApproved::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Remove accounts that never completed OTP verification within 24h.
        $schedule->job(new CleanupUnverifiedUsersJob)->hourly();
        // Permanently purge accounts whose deletion grace period elapsed.
        $schedule->job(new PruneScheduledDeletionsJob)->daily();

        $schedule->job(new SendSessionRemindersJob)->everyFiveMinutes();
        // Broadcast unhandled safety flags to all clinical staff.
        $schedule->job(new EscalateStaleRedFlagsJob)->everyFifteenMinutes();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
