<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\Module;
use App\Models\Patient;
use App\Models\Program;
use App\Models\Therapist;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

trait ClinicalCorrectionFixtures
{
    use CommittedDatabase {
        runDatabaseMigrations as private migrateCommittedDatabase;
    }

    public function runDatabaseMigrations(): void
    {
        $driver = DB::getDriverName();
        $database = DB::connection()->getDatabaseName();
        if (! $this->app->environment('testing') || ! (($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'pgsql' && str_starts_with($database, 'clinical_')))) {
            throw new \RuntimeException('Clinical fixtures require a disposable test database.');
        }
        $this->migrateCommittedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'pgsql' && ! str_starts_with(DB::connection()->getDatabaseName(), 'clinical_')) {
            throw new \RuntimeException('Clinical race fixtures require a disposable clinical_ test database.');
        }
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
        config(['sakina.whatsapp.enabled' => false, 'sakina.uploads_disk' => 'audit',
            'filesystems.disks.audit' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/sakina-clinical-correction-'.getmypid()],
            'broadcasting.default' => 'null', 'logging.default' => 'null']);
        Http::preventStrayRequests();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function user(UserRole $role, string $tz = 'UTC'): User
    {
        static $seq = 0;
        $seq++;
        $user = User::create(['name' => "Audit User {$seq}", 'email' => "audit{$seq}@example.invalid",
            'password' => bcrypt('AuditPassword123!'), 'whatsapp_number' => '+1555000'.str_pad($seq, 4, '0', STR_PAD_LEFT),
            'role' => $role, 'timezone' => $tz, 'is_active' => true, 'phone_verified_at' => now(),
            'privacy_policy_version' => 'old-version', 'privacy_accepted_at' => now()]);
        $user->assignRole($role->value);

        return $user;
    }

    protected function therapist(): array
    {
        $u = $this->user(UserRole::THERAPIST);
        $t = Therapist::create(['user_id' => $u->id, 'full_name' => $u->name, 'specialty' => 'General',
            'country' => 'US', 'languages' => ['en'], 'approval_status' => 'approved', 'clients_limit' => 20]);

        return [$u, $t];
    }

    protected function patient(?Therapist $t = null, string $tz = 'UTC'): array
    {
        $u = $this->user(UserRole::PATIENT, $tz);
        $p = Patient::create(['user_id' => $u->id, 'full_name' => $u->name, 'age' => 25,
            'gender' => 'other', 'language' => 'en', 'therapist_id' => $t?->user_id]);

        return [$u, $p];
    }

    protected function modules(int $n = 1): array
    {
        $p = Program::create(['name' => 'Audit program', 'description' => 'Audit', 'is_core' => true]);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = Module::create(['program_id' => $p->id, 'title' => "Audit {$i}", 'description' => 'Audit',
                'content_type' => 'text', 'order' => $i, 'body' => 'body', 'is_hideable' => true]);
        }

        return $out;
    }

    protected function observed(string $name, array $data): void
    {
        fwrite(STDOUT, "\nOBSERVED {$name}: ".json_encode($data, JSON_UNESCAPED_SLASHES)."\n");
    }
}
