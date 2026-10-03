<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\User;
use App\Services\Account\StaffAccountService;
use App\Services\Messaging\WhatsAppSenderInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresAuthInvitationRaceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real invitation lock races require an isolated PostgreSQL database.');
        }
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
        $this->seed(RolePermissionSeeder::class);
    }

    private function fixture(): array
    {
        $sender = new FakeWhatsAppSender;
        $this->app->instance(WhatsAppSenderInterface::class, $sender);
        $actor = User::create([
            'name' => 'Synthetic Admin', 'email' => 'invitation-race-admin@example.test',
            'whatsapp_number' => '+15556660000', 'role' => 'admin', 'password' => 'SyntheticOld1!',
            'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $invited = app(StaffAccountService::class)->invite($actor, [
            'name' => 'Synthetic Invite', 'email' => 'invitation-race@example.test',
            'whatsapp_number' => '+15556660001', 'role' => 'finance_partner',
        ])['user'];
        preg_match('/activation code ([A-Z0-9]{8})/', $sender->messages[0]['message'], $matches);

        return ['actor' => $actor->id, 'user' => $invited->id, 'phone' => $invited->whatsapp_number, 'code' => $matches[1]];
    }

    private function race(array $fixture, string $first, string $second): array
    {
        $connection = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ];
        $input = new InputStream;
        $a = new Process([PHP_BINARY, base_path('tests/Fixtures/AuthInvitationRaceWorker.php'), json_encode($fixture + ['operation' => $first, 'pause' => true])], base_path(), $environment);
        $b = new Process([PHP_BINARY, base_path('tests/Fixtures/AuthInvitationRaceWorker.php'), json_encode($fixture + ['operation' => $second, 'pause' => false])], base_path(), $environment);
        $a->setInput($input);
        $a->setTimeout(15);
        $b->setTimeout(15);
        try {
            $a->start();
            $this->assertTrue($a->waitUntil(fn () => str_contains($a->getOutput(), 'locked')), $a->getErrorOutput());
            $b->start();
            $this->assertTrue($b->waitUntil(fn () => str_contains($b->getOutput(), 'ready:')), $b->getErrorOutput());
            preg_match('/ready:(\d+)/', $b->getOutput(), $match);
            $deadline = microtime(true) + 5;
            do {
                $blocked = (bool) DB::selectOne('SELECT cardinality(pg_blocking_pids(?)) > 0 AS blocked', [(int) $match[1]])->blocked;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            $this->assertTrue($blocked, 'Second service transaction must wait for the user lock.');
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

    public function test_resend_first_invalidates_old_activation_without_deadlock(): void
    {
        $fixture = $this->fixture();
        [$resend, $activate] = $this->race($fixture, 'resend', 'activate');
        $this->assertStringContainsString('result:resent', $resend);
        $this->assertStringContainsString('result:rejected:code', $activate);
        $this->assertFalse(User::findOrFail($fixture['user'])->isVerified());
        $this->assertSame(1, AccountInvitation::where('user_id', $fixture['user'])->open()->count());
    }

    public function test_activation_first_prevents_resend_using_stale_unverified_user(): void
    {
        $fixture = $this->fixture();
        [$activate, $resend] = $this->race($fixture, 'activate', 'resend');
        $this->assertStringContainsString('result:activated', $activate);
        $this->assertStringContainsString('result:rejected:user', $resend);
        $this->assertTrue(User::findOrFail($fixture['user'])->isVerified());
        $this->assertSame(0, AccountInvitation::where('user_id', $fixture['user'])->open()->count());
    }

    public function test_simultaneous_resends_recheck_cooldown_under_user_lock(): void
    {
        $fixture = $this->fixture();
        [$first, $second] = $this->race($fixture, 'resend', 'resend');
        $this->assertStringContainsString('result:resent', $first);
        $this->assertStringContainsString('result:rejected:user', $second);
        $this->assertSame(1, AccountInvitation::where('user_id', $fixture['user'])->open()->count());
        $this->assertSame(2, AccountInvitation::where('user_id', $fixture['user'])->count());
    }
}
