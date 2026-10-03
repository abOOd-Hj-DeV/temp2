<?php

use App\Models\Patient;
use App\Models\Subscription;
use App\Services\NotificationService;
use App\Services\Session\SessionService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql') {
    throw new RuntimeException('Booking race workers require an isolated PostgreSQL test database.');
}
Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:37', 'UTC'));
Http::preventStrayRequests();
Queue::fake();
config(['sakina.package_policy.allow_pay_per_session' => false]);
$notifications = Mockery::mock(NotificationService::class);
$notifications->shouldReceive('deliver')->zeroOrMoreTimes();
$app->instance(NotificationService::class, $notifications);

$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$pause = $payload['pause'];
$lockTable = $payload['lock_table'];
DB::listen(function (QueryExecuted $query) use (&$pause, $lockTable) {
    if ($pause && str_contains($query->sql, 'from "'.$lockTable.'"') && str_contains($query->sql, 'for update')) {
        $pause = false;
        echo "locked\n";
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'continue') {
            throw new RuntimeException('Missing race barrier release.');
        }
    }
});
echo 'ready:'.DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n";
fflush(STDOUT);
try {
    if ($payload['operation'] === 'cancel') {
        $subscription = Subscription::findOrFail($payload['subscription']);
        $app->make(SubscriptionService::class)->cancel($subscription, $subscription->patient->user);
        echo "result:cancelled\n";
    } else {
        $session = $app->make(SessionService::class)->book(Patient::findOrFail($payload['patient']), [
            'therapist_id' => $payload['therapist'], 'session_date' => '2026-01-05',
            'session_time' => $payload['time'], 'medium' => 'meet',
        ]);
        echo 'result:booked:'.$session->id."\n";
    }
} catch (ValidationException $exception) {
    echo 'result:rejected:'.implode(',', array_keys($exception->errors()))."\n";
}
