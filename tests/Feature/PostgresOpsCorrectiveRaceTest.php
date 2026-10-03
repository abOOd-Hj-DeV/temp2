<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\Payment;
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

class PostgresOpsCorrectiveRaceTest extends TestCase
{
    use CommittedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real independent lock races require PostgreSQL.');
        }
        $this->travelTo(Carbon::parse('2026-11-02 10:05:00', 'UTC'));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public static function raceOrder(): array
    {
        return [['reminder', 'proof', 'therapy_sessions'], ['proof', 'reminder', 'users']];
    }

    private function appointment(): TherapySession
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
        Therapist::create(['user_id' => $users['therapist']->id, 'full_name' => 'Synthetic', 'specialty' => 'cbt', 'country' => 'JO', 'approval_status' => 'approved']);
        Patient::create(['user_id' => $users['patient']->id, 'full_name' => 'Synthetic', 'age' => 30, 'gender' => 'other', 'language' => 'en', 'therapist_id' => $users['therapist']->id]);

        return TherapySession::create([
            'patient_id' => $users['patient']->id, 'therapist_id' => $users['therapist']->id,
            'session_date' => '2026-11-03', 'session_time' => '10:00', 'status' => 'confirmed',
            'payment_status' => 'pending', 'price' => 50, 'is_initial' => false, 'medium' => 'meet',
        ]);
    }

    #[DataProvider('raceOrder')]
    public function test_reminder_and_proof_serialize_without_deadlock_in_both_orders(string $first, string $second, string $lockTable): void
    {
        $session = $this->appointment();
        $connection = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'single',
        ];
        $payload = ['session' => $session->id, 'disk_root' => Storage::disk('local')->path(''), 'lock_table' => $lockTable];
        $input = new InputStream;
        $a = new Process([PHP_BINARY, base_path('tests/Fixtures/OpsCorrectiveRaceWorker.php'), json_encode($payload + ['operation' => $first, 'pause' => true])], base_path(), $environment);
        $b = new Process([PHP_BINARY, base_path('tests/Fixtures/OpsCorrectiveRaceWorker.php'), json_encode($payload + ['operation' => $second, 'pause' => false])], base_path(), $environment);
        $a->setInput($input);
        $a->setTimeout(20);
        $b->setTimeout(20);
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
            $this->assertTrue((bool) $wait?->blocked, 'The second real connection must block on the shared fence.');
            $this->assertStringContainsString('from "users"', $wait->query);
            $input->write("continue\n");
            $input->close();
            $this->assertSame(0, $a->wait(), $a->getErrorOutput());
            $this->assertSame(0, $b->wait(), $b->getErrorOutput());
            $this->assertStringContainsString('result:'.$first, $a->getOutput());
            $this->assertStringContainsString('result:'.$second, $b->getOutput());
        } finally {
            $a->stop();
            $b->stop();
            $input->close();
        }
        $payment = Payment::where('therapy_session_id', $session->id)->sole();
        Storage::disk('local')->assertExists($payment->proof_file_path);
        $this->assertSame(1, AuditLog::where('action', 'payment.proof_submitted')->count());
        $this->assertSame(1, DB::table('ops_session_reminders')->where('session_id', $session->id)->count());
        $this->assertSame(2, DB::table('notifications')->count());
        $this->assertSame(1, NotificationLog::where('channel', 'whatsapp')->where('status', 'queued')->count());
        $this->assertSame(0, DB::transactionLevel());
    }
}
