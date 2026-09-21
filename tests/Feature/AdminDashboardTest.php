<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\PatientModule;
use App\Models\Program;
use App\Models\RedFlag;
use App\Models\Therapist;
use App\Models\User;
use App\Notifications\RedFlagRaisedNotification;
use App\Services\AuditLogService;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Services\Notifications\NotificationDispatcher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppSender $whatsApp;

    private User $admin;

    private User $headMaster;

    private User $finance;

    private User $therapistUser;

    private User $patientUser;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->whatsApp = new FakeWhatsAppSender;
        $this->app->instance(WhatsAppSenderInterface::class, $this->whatsApp);

        $this->admin = $this->makeUser('admin', '+963900000301');
        $this->headMaster = $this->makeUser('clinical_supervisor', '+963900000302');
        $this->finance = $this->makeUser('finance_partner', '+963900000303');
        $this->therapistUser = $this->makeUser('therapist', '+963900000304');
        Therapist::create([
            'user_id' => $this->therapistUser->id, 'full_name' => 'Dr. Roster', 'specialty' => 'anxiety', 'country' => 'DE',
            'languages' => ['en'], 'availability' => [], 'approval_status' => 'approved',
        ]);

        $this->patientUser = $this->makeUser('patient', '+963900000305');
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id, 'full_name' => 'Roster Patient', 'age' => 30, 'gender' => 'male', 'language' => 'ar',
            'therapist_id' => $this->therapistUser->id, 'safety_flag' => true, 'compliance_level' => 'low',
        ]);
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Test {$role}", 'email' => "{$role}.{$whatsapp}@example.com", 'password' => 'x',
            'whatsapp_number' => $whatsapp, 'role' => $role, 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function redFlag(string $status = 'open'): RedFlag
    {
        return RedFlag::create([
            'id' => (string) Str::uuid(), 'patient_id' => $this->patient->user_id, 'type' => 'safety',
            'description' => 'Auto-generated safety alert', 'status' => $status, 'priority' => 'high',
        ]);
    }

    public function test_dashboard_endpoints_are_locked_to_the_right_roles(): void
    {
        $forbiddenForEveryoneButStaff = ['/api/v1/admin/overview', '/api/v1/admin/audit', '/api/v1/admin/notifications'];
        $clinicalEndpoints = ['/api/v1/admin/patients', '/api/v1/admin/programs'];

        foreach ([$this->patientUser, $this->therapistUser] as $outsider) {
            Sanctum::actingAs($outsider, ['*'], 'api');
            foreach ([...$forbiddenForEveryoneButStaff, ...$clinicalEndpoints] as $url) {
                $this->getJson($url)->assertForbidden();
            }
        }

        // Finance staff see money, not patients or the operational dashboard.
        Sanctum::actingAs($this->finance, ['*'], 'api');
        foreach ([...$forbiddenForEveryoneButStaff, ...$clinicalEndpoints] as $url) {
            $this->getJson($url)->assertForbidden();
        }

        // Head Master manages patients and content but not the platform audit trail.
        Sanctum::actingAs($this->headMaster, ['*'], 'api');
        foreach ($forbiddenForEveryoneButStaff as $url) {
            $this->getJson($url)->assertForbidden();
        }
        foreach ($clinicalEndpoints as $url) {
            $this->getJson($url)->assertOk();
        }

        Sanctum::actingAs($this->admin, ['*'], 'api');
        foreach ([...$forbiddenForEveryoneButStaff, ...$clinicalEndpoints] as $url) {
            $this->getJson($url)->assertOk();
        }

        $this->getJson('/api/v1/admin/overview')->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.overview_viewed']);
    }

    public function test_overview_aggregates_platform_counters(): void
    {
        $this->redFlag();
        $this->redFlag('resolved');

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $overview = $this->getJson('/api/v1/admin/overview')->assertOk()->json('data');

        $this->assertSame(5, $overview['users']['total']);
        $this->assertSame(1, $overview['users']['by_role']['patient']);
        $this->assertSame(1, $overview['users']['by_role']['clinical_supervisor']);
        $this->assertSame(1, $overview['patients']['total']);
        $this->assertSame(1, $overview['patients']['with_therapist']);
        $this->assertSame(1, $overview['patients']['safety_flagged']);
        $this->assertSame(['high' => 0, 'medium' => 0, 'low' => 1], $overview['patients']['by_compliance']);
        $this->assertSame(1, $overview['therapists']['by_approval']['approved']);
        $this->assertSame(0, $overview['therapists']['by_approval']['pending']);
        $this->assertSame(['high' => 1, 'medium' => 0, 'low' => 0], $overview['clinical']['open_red_flags']);
        $this->assertSame(1, $overview['clinical']['unassigned_red_flags']);
        $this->assertSame(0, $overview['billing']['pending_payments']);
        $this->assertSame(0, $overview['sessions']['next_7_days']);
        $this->assertSame(0, $overview['notifications_last_24h']['whatsapp_failed']);
        $this->assertArrayHasKey('generated_at', $overview);
    }

    public function test_patient_directory_filters_and_audited_detail_without_clinical_content(): void
    {
        $other = $this->makeUser('patient', '+963900000306');
        Patient::create([
            'user_id' => $other->id, 'full_name' => 'Unassigned Person', 'age' => 22, 'gender' => 'female', 'language' => 'en',
        ]);
        $this->redFlag();

        Sanctum::actingAs($this->headMaster, ['*'], 'api');

        $this->assertCount(2, $this->getJson('/api/v1/admin/patients')->assertOk()->json('data'));

        $rows = $this->getJson('/api/v1/admin/patients?search=Roster')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->patient->user_id, $rows[0]['id']);
        $this->assertSame('Dr. Roster', $rows[0]['therapist']['full_name']);

        $this->assertCount(1, $this->getJson('/api/v1/admin/patients?unassigned=1')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/patients?safety_flag=1')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/patients?compliance_level=low')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/patients?search=%25')->json('data'));
        $this->getJson('/api/v1/admin/patients?compliance_level=urgent')->assertStatus(422);

        $detail = $this->getJson("/api/v1/admin/patients/{$this->patient->user_id}")->assertOk()->json('data');
        $this->assertSame(1, $detail['red_flags']['open']);
        $this->assertNull($detail['active_subscription']);
        $this->assertArrayHasKey('sessions_by_status', $detail);
        foreach (['moods', 'mood_notes', 'safety_plan', 'answers', 'messages'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $detail);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogService::PATIENT_RECORD_VIEWED,
            'user_id' => $this->headMaster->id,
            'entity_id' => $this->patient->user_id,
        ]);

        // Non-patient users and unknown ids are not enumerable through this endpoint.
        $this->getJson("/api/v1/admin/patients/{$this->therapistUser->id}")->assertNotFound();
        $this->getJson('/api/v1/admin/patients/'.Str::uuid())->assertNotFound();
        $this->getJson('/api/v1/admin/patients/not-a-uuid')->assertNotFound();
    }

    public function test_audit_log_is_filterable_and_reading_it_is_itself_audited(): void
    {
        Sanctum::actingAs($this->headMaster, ['*'], 'api');
        $this->getJson("/api/v1/admin/patients/{$this->patient->user_id}")->assertOk();

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $rows = $this->getJson('/api/v1/admin/audit?action='.AuditLogService::PATIENT_RECORD_VIEWED)->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->headMaster->id, $rows[0]['actor']['id']);
        $this->assertSame('clinical_supervisor', $rows[0]['actor']['role']);
        $this->assertSame($this->patient->user_id, $rows[0]['entity_id']);

        $this->assertCount(1, $this->getJson("/api/v1/admin/audit?user_id={$this->headMaster->id}")->json('data'));
        $this->assertCount(0, $this->getJson("/api/v1/admin/audit?user_id={$this->finance->id}")->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/audit?from='.now()->addDay()->toDateString())->json('data'));
        $this->getJson('/api/v1/admin/audit?to=2020-01-01&from=2021-01-01')->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::AUDIT_LOG_QUERIED, 'user_id' => $this->admin->id]);
    }

    public function test_notification_dispatcher_keeps_a_delivery_ledger_without_bodies(): void
    {
        $flag = $this->redFlag();
        $key = "red_flag.raised:{$flag->id}";
        $dispatcher = app(NotificationDispatcher::class);

        $this->assertTrue($dispatcher->inApp($this->admin, new RedFlagRaisedNotification($flag), $key));
        $this->assertFalse($dispatcher->inApp($this->admin, new RedFlagRaisedNotification($flag), $key));
        $this->assertSame(1, $this->admin->notifications()->count());

        $dispatcher->whatsApp($this->admin, 'Sakina alert: secret body', $key, ['red_flag_id' => $flag->id]);
        $this->assertCount(1, $this->whatsApp->messages);

        $noNumber = $this->makeUser('admin', '+963900000399');
        $noNumber->forceFill(['whatsapp_number' => ''])->save();
        $dispatcher->whatsApp($noNumber, 'unused', $key);

        $this->whatsApp->fail = true;
        $dispatcher->whatsApp($this->headMaster, 'Sakina alert: retry me', $key);

        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->admin->id, 'channel' => 'in_app', 'event' => 'red_flag.raised', 'event_key' => $key, 'status' => 'sent',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $this->admin->id, 'channel' => 'whatsapp', 'status' => 'sent', 'attempts' => 1,
        ]);
        $this->assertDatabaseHas('notification_logs', ['user_id' => $noNumber->id, 'channel' => 'whatsapp', 'status' => 'skipped']);

        $retry = NotificationLog::where('user_id', $this->headMaster->id)->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame('queued', $retry->status);
        $this->assertSame(1, $retry->attempts);
        $this->assertStringContainsString('rejected', (string) $retry->error);

        foreach (NotificationLog::all() as $log) {
            $this->assertStringNotContainsString('secret body', json_encode($log->toArray()));
            $this->assertStringNotContainsString('+96390', json_encode($log->toArray()));
        }

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $response = $this->getJson('/api/v1/admin/notifications')->assertOk();
        $this->assertSame(4, $response->json('pagination.total'));
        $this->assertSame(1, $response->json('summary.in_app.sent'));
        $this->assertSame(1, $response->json('summary.whatsapp.sent'));
        $this->assertSame(1, $response->json('summary.whatsapp.queued'));
        $this->assertSame(1, $response->json('summary.whatsapp.skipped'));
        $this->assertCount(3, $this->getJson('/api/v1/admin/notifications?channel=whatsapp')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/notifications?status=skipped')->json('data'));
        $this->assertCount(2, $this->getJson("/api/v1/admin/notifications?user_id={$this->admin->id}")->json('data'));
        $this->getJson('/api/v1/admin/notifications?channel=sms')->assertStatus(422);
    }

    public function test_head_master_manages_programs_and_modules_patients_read_them(): void
    {
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson('/api/v1/admin/programs', ['name' => 'x', 'description' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->headMaster, ['*'], 'api');
        $this->postJson('/api/v1/admin/programs', ['name' => 'x'])->assertStatus(422);

        $programId = $this->postJson('/api/v1/admin/programs', [
            'name' => 'Anxiety Basics', 'description' => 'Six-week CBT programme', 'is_core' => true,
        ])->assertCreated()->assertJsonPath('program.is_core', true)->json('program.id');
        $this->assertTrue(Str::isUuid($programId));

        $m1 = $this->postJson("/api/v1/admin/programs/{$programId}/modules", [
            'title' => 'Understanding anxiety', 'description' => 'Psychoeducation', 'content_type' => 'text',
            'tracking_tools' => ['mood_log'],
        ])->assertCreated()->assertJsonPath('module.order', 1)->json('module.id');
        $m2 = $this->postJson("/api/v1/admin/programs/{$programId}/modules", [
            'title' => 'Breathing', 'description' => 'Exercise', 'content_type' => 'video', 'exercise' => '4-7-8 breathing',
        ])->assertCreated()->assertJsonPath('module.order', 2)->json('module.id');
        $this->postJson("/api/v1/admin/programs/{$programId}/modules", [
            'title' => 'Bad', 'description' => 'x', 'content_type' => 'podcast',
        ])->assertStatus(422);

        $this->putJson("/api/v1/admin/programs/{$programId}", ['name' => 'Anxiety Basics v2'])
            ->assertOk()->assertJsonPath('program.name', 'Anxiety Basics v2')->assertJsonPath('program.modules_count', 2);
        $this->putJson("/api/v1/admin/programs/{$programId}/modules/{$m2}", ['order' => 0])
            ->assertOk()->assertJsonPath('module.order', 0);

        // A module can only be addressed through its own program.
        $otherProgram = Program::create(['name' => 'Other', 'description' => 'd', 'is_core' => false]);
        $this->putJson("/api/v1/admin/programs/{$otherProgram->id}/modules/{$m1}", ['title' => 'Hijack'])->assertNotFound();
        $this->deleteJson("/api/v1/admin/programs/{$otherProgram->id}/modules/{$m1}")->assertNotFound();
        $this->assertSame('Understanding anxiety', Module::findOrFail($m1)->title);

        $list = $this->getJson('/api/v1/admin/programs')->assertOk()->json('data');
        $this->assertSame(['Anxiety Basics v2', 'Other'], array_column($list, 'name'));
        $this->assertSame([$m2, $m1], array_column($list[0]['modules'], 'id'));

        // Content a patient has started is protected from deletion.
        PatientModule::create([
            'id' => (string) Str::uuid(), 'patient_id' => $this->patient->user_id, 'module_id' => $m1, 'status' => 'pending',
        ]);
        $this->deleteJson("/api/v1/admin/programs/{$programId}/modules/{$m1}")->assertStatus(409);
        $this->deleteJson("/api/v1/admin/programs/{$programId}")->assertStatus(409);
        $this->deleteJson("/api/v1/admin/programs/{$programId}/modules/{$m2}")->assertOk();
        $this->assertDatabaseMissing('modules', ['id' => $m2]);
        $this->deleteJson("/api/v1/admin/programs/{$otherProgram->id}")->assertOk();
        $this->assertDatabaseMissing('programs', ['id' => $otherProgram->id]);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::PROGRAM_CREATED, 'entity_id' => $programId]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::MODULE_DELETED, 'entity_id' => $m2]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::PROGRAM_DELETED, 'entity_id' => $otherProgram->id]);

        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $programs = $this->getJson('/api/v1/patients/programs')->assertOk()->json('programs');
        $this->assertCount(1, $programs);
        $this->assertSame('Anxiety Basics v2', $programs[0]['name']);
        $this->assertSame(1, $programs[0]['modules_count']);
    }
}
