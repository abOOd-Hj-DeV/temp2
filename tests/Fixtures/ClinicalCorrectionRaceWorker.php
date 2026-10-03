<?php

use App\Models\User;
use App\Services\Patient\AccountAnonymizer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
    || ! str_starts_with(DB::connection()->getDatabaseName(), 'clinical_')) {
    throw new RuntimeException('Clinical race workers require a disposable PostgreSQL test database.');
}
Carbon::setTestNow('2026-10-03 12:00:00');
Http::preventStrayRequests();
Queue::fake();
config(['sakina.whatsapp.enabled' => false, 'sakina.uploads_disk' => 'audit',
    'filesystems.disks.audit' => ['driver' => 'local', 'root' => getenv('CLINICAL_TEST_STORAGE')],
    'broadcasting.default' => 'null', 'logging.default' => 'null']);
$operation = $argv[1];
$id = $argv[2];
$barrier = $argv[3];
$paused = false;
echo 'ready:'.DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n";
fflush(STDOUT);
if ($operation === 'erase') {
    app(AccountAnonymizer::class)->anonymize(User::findOrFail($id));
    echo "erasure:success\n";
    exit;
}
if ($barrier === 'before-owner') {
    DB::connection()->beforeExecuting(function ($sql) use (&$paused): void {
        if (! $paused && str_contains($sql, 'from "users"') && str_contains($sql, 'for update')) {
            $paused = true;
            echo "before_owner\n";
            fflush(STDOUT);
            fgets(STDIN);
        }
    });
} else {
    DB::listen(function ($query) use (&$paused): void {
        if (! $paused && str_contains($query->sql, 'from "users"') && str_contains($query->sql, 'for update')) {
            $paused = true;
            echo "owner_locked\n";
            fflush(STDOUT);
            fgets(STDIN);
        }
    });
}
$token = trim(fgets(STDIN));
[$uri, $method, $payload] = match ($operation) {
    'mood' => ['/api/v1/patients/mood', 'POST', ['score' => 8, 'notes' => 'InFlightClinicalMarker']],
    'assessment' => ['/api/v1/patients/assessment', 'POST', ['type' => 'phq9', 'answers' => array_fill_keys(array_map(fn ($i) => "q$i", range(1, 9)), 0)]],
    'profile' => ['/api/v1/patients/profile', 'PUT', ['full_name' => 'InFlightClinicalMarker']],
};
$request = Request::create($uri, $method, [], [], [], [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
], json_encode($payload));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo 'http:'.$response->getStatusCode()."\n";
$kernel->terminate($request, $response);
