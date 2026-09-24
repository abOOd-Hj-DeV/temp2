<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** I-22 / ADM-08: audit log is append-only at both ORM and database level. */
class AuditLogAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->user = User::create([
            'name' => 'Actor', 'email' => 'actor@example.com', 'password' => 'x',
            'whatsapp_number' => '+963900000920', 'role' => 'patient', 'is_active' => true,
            'phone_verified_at' => now(), 'timezone' => 'UTC',
        ]);
        $this->user->assignRole('patient');
    }

    public function test_orm_update_and_delete_are_rejected(): void
    {
        app(AuditLogService::class)->record($this->user, 'test.event', $this->user->id, ['k' => 'v']);
        $log = AuditLog::firstOrFail();

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('update should be rejected');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('delete should be rejected');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'test.event']);
    }

    public function test_raw_sql_update_and_delete_are_rejected_by_the_database(): void
    {
        app(AuditLogService::class)->record($this->user, 'test.event', $this->user->id);
        $id = AuditLog::firstOrFail()->id;

        try {
            DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']);
            $this->fail('raw update should be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('audit_logs')->where('id', $id)->delete();
            $this->fail('raw delete should be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('audit_logs')->delete();
            $this->fail('bulk delete should be rejected');
        } catch (QueryException) {
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $id, 'action' => 'test.event']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_audit_rows_survive_actor_hard_delete(): void
    {
        app(AuditLogService::class)->record($this->user, 'test.event', $this->user->id);
        $userId = $this->user->id;

        $this->user->delete();

        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $userId, 'action' => 'test.event']);
    }
}
