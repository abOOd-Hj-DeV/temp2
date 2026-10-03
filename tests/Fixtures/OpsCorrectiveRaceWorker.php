<?php

use App\Jobs\SendSessionRemindersJob;
use App\Models\TherapySession;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Services\Billing\PaymentReviewService;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql') {
    throw new RuntimeException('Operations race workers require isolated PostgreSQL testing.');
}
Carbon::setTestNow(Carbon::parse('2026-11-02 10:05:00', 'UTC'));
CarbonImmutable::setTestNow('2026-11-02 10:05:00 UTC');
Http::preventStrayRequests();
Queue::fake();
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
config(['sakina.uploads_disk' => 'local', 'filesystems.disks.local.root' => $payload['disk_root']]);
DB::unprepared("SET lock_timeout = '10s'; SET statement_timeout = '12s'");
$pause = $payload['pause'];
DB::listen(function (QueryExecuted $query) use (&$pause, $payload): void {
    if ($pause && str_contains($query->sql, 'from "'.$payload['lock_table'].'"') && str_contains($query->sql, 'for update')) {
        $pause = false;
        echo "locked\n";
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'continue') {
            throw new RuntimeException('Missing operations race release.');
        }
    }
});
echo 'ready:'.DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n";
fflush(STDOUT);
if ($payload['operation'] === 'reminder') {
    (new SendSessionRemindersJob)->handle($app->make(SessionRepositoryInterface::class), $app->make(NotificationService::class));
    echo "result:reminder\n";
} else {
    $payment = $app->make(PaymentReviewService::class)->submitSessionProof(
        TherapySession::findOrFail($payload['session']), UploadedFile::fake()->create('synthetic-proof.pdf', 1, 'application/pdf')
    );
    echo 'result:proof:'.$payment->id."\n";
}
