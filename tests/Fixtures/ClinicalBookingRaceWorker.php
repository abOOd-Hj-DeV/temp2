<?php

use App\Jobs\SendSessionRemindersJob;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Services\Billing\PaymentReviewService;
use App\Services\Chat\ChatService;
use App\Services\Files\SecureFileService;
use App\Services\NotificationService;
use App\Services\Session\SessionService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
    || DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
    || ! str_starts_with(DB::connection()->getDatabaseName(), 'clinical_')) {
    throw new RuntimeException('Clinical booking workers require a disposable PostgreSQL test database.');
}
fwrite(STDOUT, "driver-witness:framework=pgsql;pdo=pgsql\n");
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
Http::preventStrayRequests();
Queue::fake();
config(['sakina.whatsapp.enabled' => false, 'logging.default' => 'null', 'broadcasting.default' => 'null',
    'sakina.uploads_disk' => 'audit', 'filesystems.disks.audit' => ['driver' => 'local', 'root' => getenv('CLINICAL_TEST_STORAGE')]]);
DB::statement("SET lock_timeout = '8s'");
DB::statement("SET statement_timeout = '15s'");
$pause = $payload['pause'];
DB::listen(function (QueryExecuted $query) use ($payload, &$pause): void {
    if (! str_contains($query->sql, 'for update')) {
        return;
    }
    if (str_contains($query->sql, 'from "users"')) {
        $ids = $query->bindings;
        if (str_contains($query->sql, 'CASE WHEN role')) {
            $ids = array_values(array_filter([$payload['patient'], $payload['therapist']], fn ($id) => in_array($id, $query->bindings, true)));
        }
        foreach ($ids as $id) {
            if ($id === $payload['patient']) {
                fwrite(STDOUT, "owner:patient\n");
                fflush(STDOUT);
                if ($pause && DB::transactionLevel() > 0) {
                    $pause = false;
                    fwrite(STDOUT, "holding_patient\n");
                    fflush(STDOUT);
                    if (trim((string) fgets(STDIN)) !== 'continue') {
                        throw new RuntimeException('Missing patient-lock barrier release.');
                    }
                }
            } elseif ($id === $payload['therapist']) {
                fwrite(STDOUT, "owner:therapist\n");
            }
        }
    } else {
        foreach (['patients', 'subscriptions', 'therapists', 'therapy_sessions', 'payments'] as $table) {
            if (str_contains($query->sql, 'from "'.$table.'"')) {
                fwrite(STDOUT, 'row:'.$table."\n");
            }
        }
    }
    fflush(STDOUT);
});
echo 'ready:'.DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n";
fflush(STDOUT);
try {
    $session = $payload['session'] ? TherapySession::findOrFail($payload['session']) : null;
    match ($payload['operation']) {
        'book' => $app->make(SessionService::class)->book(Patient::findOrFail($payload['patient']), [
            'therapist_id' => $payload['therapist'], 'session_date' => '2026-10-05', 'session_time' => '14:00', 'medium' => 'meet']),
        'report' => $app->make(SessionService::class)->report($session, User::findOrFail($payload['therapist']), 'Canonical clinical report'),
        'proof' => $app->make(PaymentReviewService::class)->submitSessionProof($session, UploadedFile::fake()->create('synthetic.pdf', 1, 'application/pdf')),
        'review' => $app->make(PaymentReviewService::class)->review(Payment::findOrFail($payload['payment']), User::where('role', 'admin')->firstOrFail(), 'reject', 'Synthetic review'),
        'reminder' => $app->make(SendSessionRemindersJob::class)->handle($app->make(SessionRepositoryInterface::class), $app->make(NotificationService::class)),
        'cancel' => $app->make(SubscriptionService::class)->cancel(Subscription::findOrFail($payload['subscription']), User::findOrFail($payload['patient']), 'Synthetic cancellation'),
        'chat-open' => $app->make(ChatService::class)->open(User::findOrFail($payload['patient']), $payload['therapist']),
        'chat-send' => $app->make(ChatService::class)->send(User::findOrFail($payload['patient']), $payload['therapist'], 'Synthetic clinical message', null),
        'download' => (function () use ($app, $payload) {
            $response = $app->make(SecureFileService::class)->download(User::findOrFail($payload['patient']), $payload['path']);
            ob_start();
            try {
                $response->sendContent();
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            fwrite(STDOUT, 'stream_sha256:'.hash('sha256', $bytes)."\n");
        })(),
    };
    echo 'result:'.$payload['operation']."\n";
} catch (ValidationException $e) {
    echo 'result:rejected:'.implode(',', array_keys($e->errors()))."\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).':'.($e instanceof QueryException ? ($e->errorInfo[0] ?? 'unknown') : 'failed')."\n");
    exit(1);
}
