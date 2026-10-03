<?php

use App\Models\User;
use App\Services\Account\StaffAccountService;
use App\Services\Auth\AuthService;
use App\Services\Messaging\WhatsAppSenderInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Feature\FakeWhatsAppSender;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql') {
    throw new RuntimeException('Invitation race workers require an isolated PostgreSQL test database.');
}
Carbon::setTestNow(Carbon::parse('2026-10-03 12:01:01', 'UTC'));
Http::preventStrayRequests();
$app->instance(WhatsAppSenderInterface::class, new FakeWhatsAppSender);
DB::statement("SET lock_timeout = '8s'");
DB::statement("SET statement_timeout = '10s'");
$payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$pause = $payload['pause'];
DB::listen(function (QueryExecuted $query) use (&$pause) {
    if ($pause && str_contains($query->sql, 'from "users"') && str_contains($query->sql, 'for update')) {
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
    if ($payload['operation'] === 'resend') {
        $app->make(StaffAccountService::class)->resendInvitation(User::findOrFail($payload['actor']), User::findOrFail($payload['user']));
        echo "result:resent\n";
    } else {
        $app->make(AuthService::class)->activate($payload['phone'], $payload['code'], 'SyntheticNew1!');
        echo "result:activated\n";
    }
} catch (ValidationException $exception) {
    echo 'result:rejected:'.implode(',', array_keys($exception->errors()))."\n";
}
