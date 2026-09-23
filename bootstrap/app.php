<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\EnsureTherapistApproved;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\LimitJsonBodySize;
use App\Http\Middleware\SecurityHeaders;
use App\Jobs\CleanupUnverifiedUsersJob;
use App\Jobs\ComputeWeeklyComplianceJob;
use App\Jobs\EscalateStaleRedFlagsJob;
use App\Jobs\PruneScheduledDeletionsJob;
use App\Jobs\RemindStalePaymentReviewsJob;
use App\Jobs\SendSessionRemindersJob;
use App\Models\IdempotencyKey as StoredIdempotencyKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use League\Flysystem\FilesystemException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Token-authenticated clients authorize private channels at POST /api/v1/broadcasting/auth.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api/v1',
        'middleware' => ['api', 'auth:api', 'status'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind TLS-terminating proxies (nginx/load balancer) so isSecure(),
        // HSTS, signed URLs and client IPs for throttling are correct.
        // Comma-separated proxy IPs/CIDRs, or '*' when the app is never reachable directly.
        if (($proxies = (string) env('TRUSTED_PROXIES', '')) !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // API-only backend: every api/* request negotiates JSON so auth
        // failures return 401 JSON instead of a redirect to a web login.
        // Global so unmatched routes (404) and other pre-routing responses carry the headers too.
        $middleware->prepend(SecurityHeaders::class);
        $middleware->api(prepend: [ForceJsonResponse::class, LimitJsonBodySize::class]);
        // Global per-user/IP ceiling on every api/* route (limiter defined in AppServiceProvider).
        $middleware->throttleApi();
        $middleware->alias([
            'role' => CheckRole::class,
            'status' => CheckUserStatus::class,
            'therapist.approved' => EnsureTherapistApproved::class,
            'idempotent' => IdempotencyKey::class,
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

        $schedule->job(new RemindStalePaymentReviewsJob)->hourly();
        // Weekly engagement snapshot (mood check-ins + module completion).
        $schedule->job(new ComputeWeeklyComplianceJob)->weeklyOn(1, '03:00');
        $schedule->call(fn () => StoredIdempotencyKey::where('expires_at', '<', now())->delete())
            ->daily()->name('prune-idempotency-keys');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Infrastructure outages (Redis cache/limiter/queue, object storage) must
        // surface as a controlled 503 rather than a 500 that leaks connection details.
        $exceptions->render(function (RedisException|FilesystemException $e, Request $request) {
            report($e);

            return response()->json(['message' => 'Service temporarily unavailable. Please retry shortly.'], 503, [
                'Retry-After' => '5',
            ]);
        });
    })->create();
