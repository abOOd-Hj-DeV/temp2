<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresBookingRepairRaceTest extends TestCase
{
    use DatabaseMigrations;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real row-lock races require an isolated PostgreSQL database.');
        }
        $this->travelTo(now('UTC')->setDate(2026, 1, 1)->setTime(8, 0, 37));
    }

    private function user(string $role): User
    {
        $n = ++$this->sequence;

        return User::create([
            'name' => "Synthetic race {$n}", 'email' => "booking-race{$n}@example.test", 'password' => 'local-test',
            'whatsapp_number' => '+96390000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'role' => $role, 'is_active' => true, 'phone_verified_at' => now(), 'timezone' => 'UTC',
        ]);
    }

    private function therapist(int $limit = 20): Therapist
    {
        $user = $this->user('therapist');

        return Therapist::create([
            'user_id' => $user->id, 'full_name' => 'Synthetic race therapist', 'specialty' => 'cbt', 'country' => 'JO',
            'clients_limit' => $limit, 'approval_status' => 'approved',
            'availability' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['00:00-23:00']),
        ]);
    }

    private function patient(Therapist $therapist): Patient
    {
        return Patient::create([
            'user_id' => $this->user('patient')->id, 'full_name' => 'Synthetic race patient', 'age' => 30,
            'gender' => 'other', 'language' => 'en', 'therapist_id' => $therapist->user_id,
        ]);
    }

    private function race(array $first, array $second, string $lockTable = 'patients'): array
    {
        $connection = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ];
        $input = new InputStream;
        $a = new Process([PHP_BINARY, base_path('tests/Fixtures/BookingRepairRaceWorker.php'), json_encode($first + ['pause' => true, 'lock_table' => $lockTable])], base_path(), $environment);
        $b = new Process([PHP_BINARY, base_path('tests/Fixtures/BookingRepairRaceWorker.php'), json_encode($second + ['pause' => false, 'lock_table' => $lockTable])], base_path(), $environment);
        $a->setInput($input);
        $a->setTimeout(15);
        $b->setTimeout(15);
        try {
            $a->start();
            $this->assertTrue($a->waitUntil(fn () => str_contains($a->getOutput(), 'locked')), $a->getErrorOutput());
            $b->start();
            $this->assertTrue($b->waitUntil(fn () => str_contains($b->getOutput(), 'ready:')), $b->getErrorOutput());
            preg_match('/ready:(\d+)/', $b->getOutput(), $match);
            $blocked = false;
            $deadline = microtime(true) + 5;
            do {
                $blocked = (bool) DB::selectOne('SELECT cardinality(pg_blocking_pids(?)) > 0 AS blocked', [(int) $match[1]])->blocked;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            $this->assertTrue($blocked, 'The second independent connection must wait on the first transaction.');
            $input->write("continue\n");
            $input->close();
            $this->assertSame(0, $a->wait(), $a->getErrorOutput());
            $this->assertSame(0, $b->wait(), $b->getErrorOutput());

            return [$a->getOutput(), $b->getOutput()];
        } finally {
            $a->stop();
            $b->stop();
        }
    }

    public function test_b1_cancellation_first_does_not_allow_booking_against_cancelled_package(): void
    {
        $therapist = $this->therapist();
        $patient = $this->patient($therapist);
        $subscription = Subscription::create([
            'patient_id' => $patient->user_id, 'therapist_id' => $therapist->user_id, 'type' => '4_weeks',
            'verification_status' => 'approved', 'start_date' => '2026-01-01', 'end_date' => '2026-01-30',
            'price' => 150, 'sessions_total' => 4, 'daily_sessions_quota' => 1,
        ]);
        [$cancel, $book] = $this->race(
            ['operation' => 'cancel', 'subscription' => $subscription->id],
            ['operation' => 'book', 'patient' => $patient->user_id, 'therapist' => $therapist->user_id, 'time' => '10:00'],
        );
        $this->assertStringContainsString('result:cancelled', $cancel);
        $this->assertStringContainsString('result:booked', $book);
        $session = TherapySession::where('patient_id', $patient->user_id)->sole();
        $this->assertNull($session->subscription_id);
        $this->assertFalse($subscription->fresh()->is_active);
    }

    public function test_b1_booking_first_is_cancelled_with_the_package(): void
    {
        $therapist = $this->therapist();
        $patient = $this->patient($therapist);
        $subscription = Subscription::create([
            'patient_id' => $patient->user_id, 'therapist_id' => $therapist->user_id, 'type' => '4_weeks',
            'verification_status' => 'approved', 'start_date' => '2026-01-01', 'end_date' => '2026-01-30',
            'price' => 150, 'sessions_total' => 4, 'daily_sessions_quota' => 1,
        ]);
        [$book, $cancel] = $this->race(
            ['operation' => 'book', 'patient' => $patient->user_id, 'therapist' => $therapist->user_id, 'time' => '10:00'],
            ['operation' => 'cancel', 'subscription' => $subscription->id],
        );
        $this->assertStringContainsString('result:booked', $book);
        $this->assertStringContainsString('result:cancelled', $cancel);
        $this->assertDatabaseHas('therapy_sessions', ['patient_id' => $patient->user_id, 'subscription_id' => $subscription->id, 'status' => 'cancelled']);
        $this->assertFalse($subscription->fresh()->is_active);
    }

    public function test_b2_parallel_bookings_cannot_overrun_therapist_capacity(): void
    {
        $therapist = $this->therapist(1);
        $a = $this->patient($therapist);
        $b = $this->patient($therapist);
        $a->update(['therapist_id' => null]);
        $b->update(['therapist_id' => null]);
        [$first, $second] = $this->race(
            ['operation' => 'book', 'patient' => $a->user_id, 'therapist' => $therapist->user_id, 'time' => '10:00'],
            ['operation' => 'book', 'patient' => $b->user_id, 'therapist' => $therapist->user_id, 'time' => '11:00'],
            'therapists',
        );
        $this->assertStringContainsString('result:booked', $first);
        $this->assertStringContainsString('result:rejected:therapist_id', $second);
        $this->assertSame(1, $therapist->fresh()->reservedClients()->count());
        $this->assertSame(1, TherapySession::count());
        $this->assertNull($b->fresh()->therapist_id);
    }
}
