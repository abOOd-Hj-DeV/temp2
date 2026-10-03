<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

class PostgresBookingErasureRaceTest extends TestCase
{
    use CommittedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real independent lock races require PostgreSQL.');
        }
        $this->travelTo(Carbon::parse('2026-11-02 10:05:00', 'UTC'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'sakina.uploads_disk' => 'local']);
        $this->app->forgetInstance('encrypter');
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public static function operationOrder(): array
    {
        return [['erase', 'book'], ['book', 'erase']];
    }

    private function actors(): array
    {
        $users = [];
        foreach (['patient', 'therapist'] as $role) {
            $user = User::create([
                'name' => 'Synthetic '.$role, 'email' => Str::uuid().'@example.invalid', 'password' => 'test-only',
                'whatsapp_number' => '+1555'.random_int(1000000, 9999999), 'role' => $role,
                'is_active' => true, 'phone_verified_at' => now(), 'timezone' => 'UTC',
            ]);
            $user->assignRole($role);
            $users[$role] = $user;
        }
        $therapist = Therapist::create([
            'user_id' => $users['therapist']->id, 'full_name' => 'Synthetic', 'specialty' => 'cbt', 'country' => 'JO',
            'approval_status' => 'approved', 'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['00:00-23:59']
            ),
        ]);
        $patient = Patient::create([
            'user_id' => $users['patient']->id, 'full_name' => 'Synthetic', 'age' => 30,
            'gender' => 'other', 'language' => 'en', 'therapist_id' => $therapist->user_id,
        ]);

        return [$patient, $therapist];
    }

    #[DataProvider('operationOrder')]
    public function test_booking_and_erasure_serialize_on_patient_user_fence(string $first, string $second): void
    {
        [$patient, $therapist] = $this->actors();
        $connection = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'single',
        ];
        $common = [
            'patient' => $patient->user_id, 'therapist' => $therapist->user_id,
            'disk_root' => Storage::disk('local')->path(''), 'lock_table' => 'users',
        ];
        $input = new InputStream;
        $a = new Process([PHP_BINARY, base_path('tests/Fixtures/OpsCorrectiveRaceWorker.php'), json_encode($common + ['operation' => $first, 'pause' => true])], base_path(), $environment);
        $b = new Process([PHP_BINARY, base_path('tests/Fixtures/OpsCorrectiveRaceWorker.php'), json_encode($common + ['operation' => $second, 'pause' => false])], base_path(), $environment);
        $a->setInput($input);
        $a->setTimeout(25);
        $b->setTimeout(25);
        try {
            $a->start();
            $this->assertTrue($a->waitUntil(fn () => str_contains($a->getOutput(), 'locked')), $a->getErrorOutput());
            $b->start();
            $this->assertTrue($b->waitUntil(fn () => str_contains($b->getOutput(), 'ready:')), $b->getErrorOutput());
            preg_match('/ready:(\d+)/', $b->getOutput(), $match);
            $wait = null;
            $deadline = microtime(true) + 5;
            do {
                $wait = DB::selectOne('SELECT cardinality(pg_blocking_pids(pid)) > 0 AS blocked, query FROM pg_stat_activity WHERE pid = ?', [(int) $match[1]]);
                if (! $wait?->blocked) {
                    usleep(10000);
                }
            } while (! $wait?->blocked && microtime(true) < $deadline);
            $this->assertTrue((bool) $wait?->blocked, 'The second connection must block on the patient User fence.');
            $this->assertStringContainsString('from "users"', $wait->query);
            $input->write("continue\n");
            $input->close();
            $this->assertSame(0, $a->wait(), $a->getErrorOutput());
            $this->assertSame(0, $b->wait(), $b->getErrorOutput());
        } finally {
            $a->stop();
            $b->stop();
            $input->close();
        }
        $this->assertStringContainsString('result:'.$first, $a->getOutput());
        $this->assertStringContainsString('result:'.($second === 'book' ? 'rejected:erasure' : 'erased'), $b->getOutput());
        $this->assertFalse($patient->user->fresh()->is_active);
        $this->assertNotNull($patient->user->fresh()->anonymized_at);
        $this->assertNotNull(DB::table('clinical_erasure_plans')->where('user_id', $patient->user_id)->value('completed_at'));
        $this->assertNull($patient->fresh()->therapist_id);
        $this->assertSame(0, NotificationLog::where('user_id', $patient->user_id)->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $patient->user_id)->count());
        $this->assertSame($first === 'book' ? 1 : 0, TherapySession::where('patient_id', $patient->user_id)->count());
        $this->assertSame(0, DB::transactionLevel());
    }
}
