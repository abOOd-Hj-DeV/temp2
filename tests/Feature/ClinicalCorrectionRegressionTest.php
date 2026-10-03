<?php

namespace Tests\Feature;

use App\Enums\RedFlagPriority;
use App\Enums\RedFlagType;
use App\Enums\UserRole;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\ClinicalNotificationEvent;
use App\Models\Message;
use App\Models\MoodLog;
use App\Models\ParallelLayer;
use App\Models\PatientModule;
use App\Models\SafetyPlan;
use App\Models\SessionReportRevision;
use App\Models\Subscription;
use App\Models\TherapistContent;
use App\Models\TherapySession;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Assessment\AssessmentService;
use App\Services\AuditLogService;
use App\Services\Files\SecureFileService;
use App\Services\Mood\MoodService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationOutboxService;
use App\Services\Patient\AccountAnonymizer;
use App\Services\Patient\ClinicalEncryption;
use App\Services\Patient\ClinicalEventDelivery;
use App\Services\Patient\ComplianceService;
use App\Services\Patient\PatientAccountService;
use App\Services\Patient\PatientDashboardService;
use App\Services\Patient\PatientProfileService;
use App\Services\Program\ModuleAccessService;
use App\Services\RedFlagService;
use App\Services\Session\SessionRecommendationService;
use App\Services\Session\SessionService;
use App\Services\Therapist\ParallelLayerService;
use App\Services\Therapist\TherapistBlockedPeriodService;
use App\Services\Therapist\TherapistClientService;
use App\Services\Therapist\TherapistContentService;
use App\Services\Therapist\TherapistReviewService;
use App\Services\Therapist\TherapistSwitchService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\ClinicalCorrectionFixtures;
use Tests\TestCase;
use Throwable;

final class ClinicalCorrectionRegressionTest extends TestCase
{
    use ClinicalCorrectionFixtures;

    public function test_novel_redflag_resolve_encrypts_staff_action(): void
    {
        [$u, $p] = $this->patient();
        $staff = $this->user(UserRole::CLINICAL_SUPERVISOR);
        $flag = app(RedFlagService::class)->createFromMood($p, RedFlagType::SAFETY, RedFlagPriority::HIGH, 'Synthetic safety concern');
        $this->actingAs($staff, 'api');
        $this->postJson("/api/v1/admin/red-flags/{$flag->id}/status", ['status' => 'resolved', 'action_taken' => 'ClinicalActionMarker contacted patient about self-harm'])->assertOk();
        $raw = DB::table('red_flags')->where('id', $flag->id)->value('action_taken');
        $this->observed('redflag-action-raw', ['raw' => $raw, 'model' => $flag->fresh()->action_taken]);
        $this->assertStringStartsWith('clinical:v1:', $raw);
        $this->assertStringNotContainsString('ClinicalActionMarker', $raw);
    }

    public function test_novel_same_second_latest_mood_is_consistent(): void
    {
        [$u, $p] = $this->patient();
        Carbon::setTestNow('2026-10-01 12:00:00');
        app(MoodService::class)->log($p, ['score' => 2]);
        Carbon::setTestNow('2026-10-02 12:00:00');
        app(MoodService::class)->log($p, ['score' => 2]);
        Carbon::setTestNow('2026-10-03 12:00:00');
        $this->actingAs($u, 'api');
        $this->postJson('/api/v1/patients/mood', ['score' => 2])->assertCreated();
        $this->postJson('/api/v1/patients/mood', ['score' => 8])->assertCreated();
        $chart = $this->getJson('/api/v1/patients/mood/chart?days=7')->assertOk()->json();
        $this->observed('same-second-mood', ['chart_last' => $chart['series'][6], 'low_streak' => $chart['summary']['current_low_streak']]);
        $this->assertSame(8, $chart['series'][6]['score']);
        $this->assertSame(0, $chart['summary']['current_low_streak']);
    }

    public function test_novel_legacy_messages_are_in_data_export(): void
    {
        [$tu, $t] = $this->therapist();
        [$u, $p] = $this->patient($t);
        Message::create(['sender_id' => $u->id, 'receiver_id' => $tu->id, 'content' => 'LegacyClinicalExportMarker', 'timestamp' => now(), 'is_read' => false]);
        $this->actingAs($u, 'api');
        $r = $this->getJson('/api/v1/patients/export-data')->assertOk();
        $export = $r->streamedContent();
        $this->observed('legacy-export', ['legacy_rows' => Message::count(), 'conversations' => json_decode($export, true)['conversations'], 'marker_present' => str_contains($export, 'LegacyClinicalExportMarker')]);
        $this->assertStringContainsString('LegacyClinicalExportMarker', $export);
        [$other, $otherPatient] = $this->patient($t);
        Message::create(['sender_id' => $other->id, 'receiver_id' => $tu->id, 'content' => 'OtherPatientPrivateMarker', 'timestamp' => now(), 'is_read' => false]);
        $this->assertStringNotContainsString('OtherPatientPrivateMarker', $this->getJson('/api/v1/patients/export-data')->assertOk()->streamedContent());
    }

    public function test_novel_shared_content_erasure_inventory_covers_nonprimary_assignee(): void
    {
        [$tu, $t] = $this->therapist();
        [$first, $p1] = $this->patient($t);
        [$u, $p2] = $this->patient($t);
        $this->actingAs($tu, 'api');
        $r = $this->postJson('/api/v1/therapists/content', ['title' => 'Synthetic exercise', 'content_type' => 'text', 'body' => $u->name.' clinical memory at +15551112222', 'patient_ids' => [$first->id, $u->id]])->assertCreated();
        $u->forceFill(['whatsapp_number' => '+15551112222'])->save();
        $item = TherapistContent::firstOrFail();
        app(AccountAnonymizer::class)->anonymize($u);
        $this->actingAs($first, 'api');
        $r = $this->getJson('/api/v1/patients/content')->assertOk();
        $this->observed('secondary-content-erasure', ['anonymized' => $u->fresh()->anonymized_at !== null, 'primary_patient_is_other' => $item->patient_id === $first->id, 'body_after' => $item->fresh()->body, 'visible_to_other_patient' => str_contains($r->getContent(), $u->name)]);
        $this->assertStringNotContainsString($u->name, $item->fresh()->body);
        $this->assertStringNotContainsString('+15551112222', $item->fresh()->body);
    }

    public function test_novel_encryption_backfill_roundtrip_idempotency_and_malformed_ciphertext(): void
    {
        [$u, $p] = $this->patient();
        $row = MoodLog::create(['id' => (string) Str::uuid(), 'patient_id' => $u->id, 'log_date' => now()->toDateString(), 'score' => 8, 'notes' => 'First']);
        DB::table('mood_logs')->where('id', $row->id)->update(['notes' => 'LegacyClinicalMarker']);
        app(ClinicalEncryption::class)->backfill();
        $cipher = $row->fresh()->getRawOriginal('notes');
        app(ClinicalEncryption::class)->backfill();
        $this->assertSame($cipher, $row->fresh()->getRawOriginal('notes'));
        $this->assertSame('LegacyClinicalMarker', $row->fresh()->notes);
        DB::table('mood_logs')->where('id', $row->id)->update(['notes' => 'clinical:v1:invalid']);
        $this->expectException(DecryptException::class);
        $row->fresh()->notes;
    }

    public function test_novel_populated_parallel_duplicates_have_safe_migration(): void
    {
        [$tu, $t] = $this->therapist();
        [$u, $p] = $this->patient($t);
        Schema::table('parallel_layers', fn ($table) => $table->dropUnique('clinical_parallel_patient_therapist_unique'));
        Schema::drop('clinical_erasure_plans');
        foreach (['Legacy A', 'Legacy B'] as $text) {
            ParallelLayer::create(['id' => (string) Str::uuid(), 'patient_id' => $u->id, 'therapist_id' => $tu->id, 'content' => ['text' => $text]]);
        }
        $before = DB::table('parallel_layers')->orderBy('id')->get()->toJson();
        $error = null;
        try {
            (require database_path('migrations/2026_10_03_210003_clinical_erasure_and_parallel_uniqueness.php'))->up();
        } catch (Throwable $e) {
            $error = get_class($e).': '.$e->getMessage();
        }
        $this->observed('populated-duplicate-migration', ['existing_layers' => ParallelLayer::count(), 'error' => $error]);
        $this->assertStringContainsString('approved non-destructive reconciliation', $error);
        $this->assertSame(2, ParallelLayer::count());
        $this->assertSame($before, DB::table('parallel_layers')->orderBy('id')->get()->toJson());
        $this->assertSame(['Legacy A', 'Legacy B'], ParallelLayer::orderBy('created_at')->get()->map(fn ($row) => $row->content['text'])->all());
        $this->assertFalse(Schema::hasTable('clinical_erasure_plans'));
        $this->assertFalse(Schema::hasIndex('parallel_layers', 'clinical_parallel_patient_therapist_unique'));
    }

    public function test_novel_populated_legacy_prefix_does_not_break_backfill(): void
    {
        [$u, $p] = $this->patient();
        $row = MoodLog::create(['id' => (string) Str::uuid(), 'patient_id' => $u->id, 'log_date' => now()->toDateString(), 'score' => 8]);
        DB::table('mood_logs')->where('id', $row->id)->update(['notes' => 'clinical:v1: previously permitted patient-authored text']);
        $error = null;
        try {
            app(ClinicalEncryption::class)->backfill();
        } catch (Throwable $e) {
            $error = get_class($e).': '.$e->getMessage();
        }
        $this->observed('legacy-prefix-backfill', ['error' => $error]);
        $this->actingAs($u, 'api');
        $r = $this->getJson('/api/v1/patients/mood/history');
        $this->observed('legacy-prefix-history', ['http' => $r->getStatusCode()]);
        $this->assertNull($error);
        $this->assertStringNotContainsString('previously permitted patient-authored text', DB::table('mood_logs')->where('id', $row->id)->value('notes'));
        $this->assertSame('clinical:v1: previously permitted patient-authored text', $row->fresh()->notes);
    }

    public function test_novel_generic_and_support_files_are_inaccessible_after_erasure(): void
    {
        [$u, $p] = $this->patient();
        $admin = $this->user(UserRole::SUPER_ADMIN);
        $this->actingAs($u, 'api');
        $path = app(SecureFileService::class)->upload($u, UploadedFile::fake()->create('local.pdf', 2, 'application/pdf'), 'other')['path'];
        $r = $this->post('/api/v1/patients/support', ['type' => 'technical', 'description' => 'Synthetic', 'attachment' => UploadedFile::fake()->create('support.pdf', 2, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $id = $r->json('data.id');
        app(AccountAnonymizer::class)->anonymize($u);
        $this->assertFalse(Storage::disk('audit')->exists($path));
        $this->actingAs($admin, 'api');
        $this->postJson('/api/v1/files/download', ['path' => $path])->assertNotFound();
        $this->postJson('/api/v1/files/download', ['path' => "support/{$id}/attachment"])->assertNotFound();
    }

    public function test_novel_erasure_resumes_and_stale_patient_writes_are_denied(): void
    {
        [$u, $p] = $this->patient();
        $path = "uploads/{$u->id}/other/local.txt";
        Storage::disk('audit')->put($path, 'synthetic');
        $real = Storage::disk('audit');
        $adapter = Mockery::mock($real)->makePartial();
        $adapter->shouldReceive('deleteDirectory')->andReturn(false);
        Storage::set('audit', $adapter);
        try {
            app(AccountAnonymizer::class)->anonymize($u);
            $this->fail('Expected injected failure');
        } catch (RuntimeException $e) {
        }
        $this->assertFalse($u->fresh()->is_active);
        $this->assertNull($u->fresh()->anonymized_at);
        $this->assertDatabaseHas('clinical_erasure_plans', ['user_id' => $u->id, 'completed_at' => null]);
        Storage::set('audit', $real);
        app(AccountAnonymizer::class)->anonymize($u);
        $this->assertNotNull($u->fresh()->anonymized_at);
        $this->assertFalse($real->exists($path));
        $this->actingAs($u->fresh(), 'api');
        $this->postJson('/api/v1/patients/mood', ['score' => 8])->assertForbidden();
    }

    public function test_novel_parallel_delivery_is_after_commit_retryable_and_idempotent(): void
    {
        [$tu, $t] = $this->therapist();
        [$u, $p] = $this->patient($t);
        DB::beginTransaction();
        $event = app(ClinicalEventDelivery::class)->record($u->id, 'parallel_layer_updated', (string) Str::uuid());
        $this->assertSame(0, DB::table('notifications')->count());
        DB::rollBack();
        $this->assertSame(0, ClinicalNotificationEvent::count());
        $event = app(ClinicalEventDelivery::class)->record($u->id, 'parallel_layer_updated', (string) Str::uuid());
        $this->assertNotNull($event->fresh()->delivered_at);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $u->id)->count());
        app(ClinicalEventDelivery::class)->deliver($event->id);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $u->id)->count());
        $this->assertStringNotContainsString('clinical memory', DB::table('notifications')->value('data'));
    }

    public function test_novel_unavailable_modules_do_not_create_false_noncompliance(): void
    {
        [$tu, $t] = $this->therapist();
        [$u, $p] = $this->patient($t);
        $modules = $this->modules(10);
        $this->actingAs($tu, 'api');
        foreach (array_slice($modules, 1) as $m) {
            $this->postJson("/api/v1/therapists/clients/{$u->id}/modules/{$m->id}/hide")->assertOk();
            $this->deleteJson("/api/v1/therapists/clients/{$u->id}/modules/{$m->id}/hide")->assertOk();
        }
        $this->actingAs($u, 'api');
        $this->postJson("/api/v1/patients/modules/{$modules[0]->id}/complete")->assertOk();
        $this->getJson("/api/v1/patients/modules/{$modules[1]->id}")->assertForbidden();
        foreach (range(0, 4) as $day) {
            app(MoodService::class)->log($p, ['score' => 8, 'log_date' => now()->subDays($day)->toDateString()]);
        }
        $snapshot = app(ComplianceService::class)->snapshot($p, now()->addDay());
        $this->observed('subscription-locked-compliance', $snapshot);
        $this->assertSame(1, $snapshot['modules_due']);
    }

    public function test_novel_emergency_pending_without_staff_retries_and_does_not_expose_note(): void
    {
        [$u, $p] = $this->patient();
        $this->actingAs($u, 'api');
        $r = $this->postJson('/api/v1/patients/emergency/alert', ['note' => 'MustNotLeakClinicalNote'])->assertCreated();
        $this->assertSame('pending', $r->json('escalation.status'));
        $staff = $this->user(UserRole::CLINICAL_SUPERVISOR);
        $id = $r->json('escalation.id');
        app(ClinicalEventDelivery::class)->deliver($id);
        $this->assertNotNull(ClinicalNotificationEvent::find($id)->delivered_at);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $staff->id)->count());
        app(ClinicalEventDelivery::class)->deliver($id);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $staff->id)->count());
        $this->assertStringNotContainsString('MustNotLeakClinicalNote', DB::table('notifications')->value('data'));
    }

    public function test_novel_populated_json_to_encrypted_text_migration(): void
    {
        [$u, $p] = $this->patient();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE safety_plans ALTER COLUMN contact_info TYPE json USING contact_info::json');
            DB::statement('ALTER TABLE parallel_layers ALTER COLUMN content TYPE json USING content::json');
        } else {
            Schema::table('safety_plans', fn ($table) => $table->json('contact_info')->change());
            Schema::table('parallel_layers', fn ($table) => $table->json('content')->change());
        }
        $id = (string) Str::uuid();
        DB::table('safety_plans')->insert(['id' => $id, 'patient_id' => $u->id, 'contact_info' => json_encode(['name' => 'SyntheticLegacyContact']), 'coping_strategies' => 'Synthetic legacy coping', 'created_at' => now(), 'updated_at' => now()]);
        (require database_path('migrations/2026_10_03_210002_encrypt_clinical_free_text.php'))->up();
        $plan = SafetyPlan::findOrFail($id);
        $this->assertStringStartsWith('clinical:v1:', $plan->getRawOriginal('contact_info'));
        $this->assertSame(['name' => 'SyntheticLegacyContact'], $plan->contact_info);
        $this->assertSame('Synthetic legacy coping', $plan->coping_strategies);
    }

    public function test_novel_former_therapist_cannot_assign_new_current_care_content(): void
    {
        [$oldUser, $old] = $this->therapist();
        [$newUser, $new] = $this->therapist();
        [$u, $p] = $this->patient($new);
        TherapySession::create(['patient_id' => $u->id, 'therapist_id' => $old->user_id, 'session_date' => now()->subMonth()->toDateString(), 'session_time' => '10:00', 'medium' => 'meet', 'price' => 0, 'status' => 'completed', 'payment_status' => 'paid']);
        $this->actingAs($oldUser, 'api');
        $this->postJson("/api/v1/therapists/clients/{$u->id}/notes", ['body' => 'ShouldNotCreate'])->assertNotFound();
        $r = $this->postJson('/api/v1/therapists/content', ['title' => 'New assigned care after transfer', 'content_type' => 'text', 'body' => 'FormerTherapistCurrentCareMarker', 'patient_ids' => [$u->id]]);
        $this->actingAs($u, 'api');
        $visible = $this->getJson('/api/v1/patients/content')->assertOk();
        $this->observed('former-therapist-current-content', ['post_http' => $r->getStatusCode(), 'patient_visible' => str_contains($visible->getContent(), 'FormerTherapistCurrentCareMarker')]);
        $this->assertSame(404, $r->getStatusCode());
        $this->assertStringNotContainsString('FormerTherapistCurrentCareMarker', $visible->getContent());
        $library = app(TherapistContentService::class)->create($old, [
            'title' => 'Private library item', 'content_type' => 'text', 'body' => 'Library marker',
        ]);
        $this->actingAs($oldUser, 'api');
        $this->postJson("/api/v1/therapists/content/{$library->id}/assign", ['patient_ids' => [$u->id]])->assertNotFound();
        $this->assertSame(0, $library->assignedPatients()->count());
        $this->actingAs($newUser, 'api');
        $this->postJson('/api/v1/therapists/content', ['title' => 'Current care', 'content_type' => 'text',
            'body' => 'AuthorizedCurrentCareMarker', 'patient_id' => $u->id])->assertCreated();
        $this->actingAs($u, 'api');
        $this->assertStringContainsString('AuthorizedCurrentCareMarker', $this->getJson('/api/v1/patients/content')->assertOk()->getContent());
    }

    public function test_novel_repeat_emergency_stages_a_fresh_critical_whatsapp_event(): void
    {
        $staff = $this->user(UserRole::CLINICAL_SUPERVISOR);
        [$u, $p] = $this->patient();
        app(RedFlagService::class)->createFromMood($p, RedFlagType::SAFETY, RedFlagPriority::HIGH, 'Old concern');
        $before = DB::table('notification_logs')->where('channel', 'whatsapp')->count();
        $notifications = DB::table('notifications')->where('notifiable_id', $staff->id)->count();
        $this->actingAs($u, 'api');
        $r = $this->postJson('/api/v1/patients/emergency/alert')->assertCreated();
        $after = DB::table('notification_logs')->where('channel', 'whatsapp')->count();
        $this->observed('repeat-emergency-channels', ['escalation' => $r->json('escalation'), 'new_inapp' => DB::table('notifications')->where('notifiable_id', $staff->id)->count() - $notifications, 'new_whatsapp_jobs_staged' => $after - $before]);
        $this->assertSame($before + 1, $after);
        $eventId = $r->json('escalation.id');
        $outboxCount = DB::table('ops_notification_outbox')->count();
        app(ClinicalEventDelivery::class)->deliver($eventId);
        $this->assertSame($after, DB::table('notification_logs')->where('channel', 'whatsapp')->count());
        $this->assertSame($outboxCount, DB::table('ops_notification_outbox')->count());
        $next = $this->postJson('/api/v1/patients/emergency/alert')->assertCreated();
        $this->assertNotSame($eventId, $next->json('escalation.id'));
        $this->assertSame($after + 1, DB::table('notification_logs')->where('channel', 'whatsapp')->count());
    }

    public static function deletionFailures(): array
    {
        return [['update-false'], ['audit'], ['revoke']];
    }

    #[DataProvider('deletionFailures')]
    public function test_e3_scheduling_failure_rolls_back_mutation_tokens_credentials_and_audit(string $failure): void
    {
        [$u, $p] = $this->patient();
        $u->createToken('synthetic');
        $version = $u->fresh()->credential_version;
        if ($failure === 'update-false') {
            $this->mock(UserRepositoryInterface::class)
                ->shouldReceive('update')->once()->andReturn(false);
        } elseif ($failure === 'audit') {
            $this->mock(AuditLogService::class)->shouldReceive('record')->once()
                ->andReturnUsing(function () use ($u) {
                    AuditLog::create(['id' => Str::uuid(), 'user_id' => $u->id, 'action' => 'injected', 'timestamp' => now()]);
                    throw new RuntimeException('Injected audit failure.');
                });
        } else {
            DB::connection()->beforeExecuting(function ($sql): void {
                if (str_contains($sql, 'delete from "personal_access_tokens"')) {
                    throw new RuntimeException('Injected revocation failure.');
                }
            });
        }
        try {
            app(PatientAccountService::class)->requestDeletion($u);
            $this->fail('Deletion scheduling must fail atomically.');
        } catch (RuntimeException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertNull($u->fresh()->deletion_scheduled_at);
        $this->assertTrue($u->fresh()->is_active);
        $this->assertEquals($version, $u->fresh()->credential_version);
        $this->assertSame(1, $u->tokens()->count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_e3_success_commits_scheduling_revocation_and_audit_together(): void
    {
        [$u, $p] = $this->patient();
        $u->createToken('synthetic');
        app(PatientAccountService::class)->requestDeletion($u);
        $this->assertNotNull($u->fresh()->deletion_scheduled_at);
        $this->assertSame(0, $u->tokens()->count());
        $this->assertSame(1, AuditLog::where('action', AuditLogService::ACCOUNT_DELETION_REQUESTED)->count());
    }

    public static function clinicalMutations(): array
    {
        return array_map(fn ($action) => [$action], [
            'profile', 'mood', 'assessment', 'homework', 'complete', 'emergency', 'note', 'parallel',
            'flag-create', 'flag-status', 'compliance', 'event', 'content', 'review', 'report', 'recommendation',
        ]);
    }

    #[DataProvider('clinicalMutations')]
    public function test_n7_pending_erasure_blocks_clinical_mutations_with_a_stale_authenticated_owner(string $action): void
    {
        [$tu, $t] = $this->therapist();
        [$u, $p] = $this->patient($t);
        [$m] = $this->modules();
        $session = TherapySession::create(['patient_id' => $u->id, 'therapist_id' => $tu->id,
            'session_date' => now()->subDay()->toDateString(), 'session_time' => '10:00',
            'medium' => 'meet', 'status' => 'completed', 'payment_status' => 'paid', 'price' => 0]);
        $flag = app(RedFlagService::class)->createFromMood($p, RedFlagType::SAFETY, RedFlagPriority::HIGH, 'Original concern');
        $content = app(TherapistContentService::class)->create($t, [
            'title' => 'Original content', 'content_type' => 'text', 'body' => 'Original body', 'patient_id' => $u->id,
        ]);
        DB::table('clinical_erasure_plans')->insert(['user_id' => $u->id, 'paths' => '[]', 'directories' => '[]',
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($u->is_active, 'Simulate the gate having passed before the erasure marker appeared.');
        $this->expectException(AccessDeniedHttpException::class);
        match ($action) {
            'profile' => app(PatientProfileService::class)->upsertProfile($u, ['full_name' => 'ShouldNotWrite']),
            'mood' => app(MoodService::class)->log($p, ['score' => 8, 'notes' => 'ShouldNotWrite']),
            'assessment' => app(AssessmentService::class)->createAssessment($u, [
                'type' => 'phq9', 'answers' => array_fill_keys(array_map(fn ($i) => "q$i", range(1, 9)), 0)]),
            'homework' => app(PatientDashboardService::class)->submitHomework($u, $m->id, ['answer' => 'ShouldNotWrite']),
            'complete' => app(PatientDashboardService::class)->completeModule($u, $m->id, ['answer' => 'ShouldNotWrite']),
            'emergency' => app(PatientDashboardService::class)->emergencyAlert($u),
            'note' => app(TherapistClientService::class)->addNote($t, $u->id, 'ShouldNotWrite'),
            'parallel' => app(ParallelLayerService::class)->save($t, $u->id, ['body' => 'ShouldNotWrite']),
            'flag-create' => app(RedFlagService::class)->createFromMood($p, RedFlagType::LOW_MOOD, RedFlagPriority::HIGH, 'ShouldNotWrite'),
            'flag-status' => app(RedFlagService::class)->updateStatus($flag->id, 'resolved', 'ShouldNotWrite'),
            'compliance' => app(ComplianceService::class)->apply($p, now()),
            'event' => app(ClinicalEventDelivery::class)->record($u->id, 'parallel_layer_updated', (string) Str::uuid()),
            'content' => app(TherapistContentService::class)->update($t, $content->id, ['body' => 'ShouldNotWrite']),
            'review' => app(TherapistReviewService::class)->create($p, $tu->id, ['rating' => 5, 'comment' => 'ShouldNotWrite']),
            'report' => app(SessionService::class)->report($session, $tu, 'ShouldNotWrite'),
            'recommendation' => app(SessionRecommendationService::class)->save($session, $tu, ['note' => 'ShouldNotWrite']),
        };
    }

    public static function erasureSchedules(): array
    {
        return [['mood', 'before-owner'], ['assessment', 'before-owner'], ['profile', 'before-owner'],
            ['mood', 'holding-owner'], ['assessment', 'holding-owner'], ['profile', 'holding-owner']];
    }

    private function raceWorker(string $operation, string $ownerId, string $barrier): Process
    {
        $connection = config('database.connections.pgsql');

        return new Process([PHP_BINARY, base_path('tests/Fixtures/ClinicalCorrectionRaceWorker.php'), $operation, $ownerId, $barrier],
            base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
                'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => $connection['username'],
                'DB_PASSWORD' => $connection['password'], 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
                'CLINICAL_TEST_STORAGE' => config('filesystems.disks.audit.root'),
            ], timeout: 20);
    }

    #[DataProvider('erasureSchedules')]
    public function test_n7_actual_postgres_authenticated_writes_and_erasure_serialize_without_deadlock(string $operation, string $barrier): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real row-lock schedules require PostgreSQL.');
        }
        [$u, $p] = $this->patient();
        $input = new InputStream;
        $a = $this->raceWorker($operation, $u->id, $barrier);
        $b = $this->raceWorker('erase', $u->id, $barrier);
        $a->setInput($input);
        try {
            $a->start();
            $input->write($u->createToken('synthetic-race')->plainTextToken."\n");
            $marker = $barrier === 'before-owner' ? 'before_owner' : 'owner_locked';
            $this->assertTrue($a->waitUntil(fn () => str_contains($a->getOutput(), $marker)), $a->getErrorOutput());
            if ($barrier === 'before-owner') {
                app(AccountAnonymizer::class)->anonymize($u);
                $this->assertNotNull($u->fresh()->anonymized_at);
            } else {
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
                $this->assertTrue($blocked, 'Erasure must wait on the clinical owner lock, not acquire an opposing lock.');
            }
            $input->write("continue\n");
            $input->close();
            $this->assertSame(0, $a->wait(), $a->getErrorOutput());
            $expected = $barrier === 'before-owner' ? 403 : ($operation === 'profile' ? 200 : 201);
            $this->assertStringContainsString('http:'.$expected, $a->getOutput());
            if ($barrier !== 'before-owner') {
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());
                $this->assertStringContainsString('erasure:success', $b->getOutput());
            }
            $this->assertNotNull($u->fresh()->anonymized_at);
            $this->assertFalse($u->fresh()->is_active);
            $this->assertSame(0, MoodLog::where('patient_id', $u->id)->whereNotNull('notes')->count());
            $assessments = Assessment::where('patient_id', $u->id)->get();
            $this->assertCount($operation === 'assessment' && $barrier === 'holding-owner' ? 1 : 0, $assessments);
            foreach ($assessments as $assessment) {
                $this->assertSame(array_fill_keys(array_map(fn ($i) => "q$i", range(1, 9)), 0), $assessment->answers);
            }
            $this->assertStringNotContainsString('InFlightClinicalMarker', $p->fresh()->full_name);
            $this->observed('pg-clinical-erasure-'.$operation.'-'.$barrier, ['http' => $expected, 'deadlock' => false]);
        } finally {
            $a->stop();
            $b->stop();
        }
    }

    public function test_n5_authenticated_envelopes_with_a_wrong_key_fail_closed_instead_of_becoming_collision_plaintext(): void
    {
        [$u, $p] = $this->patient();
        $row = MoodLog::create(['id' => Str::uuid(), 'patient_id' => $u->id, 'log_date' => now()->toDateString(),
            'score' => 8, 'notes' => 'Preserve original encrypted record']);
        $original = $row->getRawOriginal('notes');
        $encrypter = app('encrypter');
        Crypt::swap(new Encrypter(random_bytes(32), config('app.cipher')));
        try {
            app(ClinicalEncryption::class)->backfill();
            $this->fail('A recognizable ciphertext envelope must not be silently reclassified as legacy plaintext.');
        } catch (DecryptException) {
            $this->assertSame($original, DB::table('mood_logs')->where('id', $row->id)->value('notes'));
        } finally {
            Crypt::swap($encrypter);
        }
    }

    private function legacySessionReports(bool $corrupt = false): array
    {
        [$tu, $t] = $this->therapist();
        [$u, $p] = $this->patient($t);
        $session = TherapySession::create(['patient_id' => $u->id, 'therapist_id' => $tu->id,
            'session_date' => now()->subDay()->toDateString(), 'session_time' => '10:00',
            'medium' => 'meet', 'status' => 'completed', 'payment_status' => 'paid', 'price' => 0]);
        DB::table('therapy_sessions')->where('id', $session->id)->update(['summary' => 'Legacy current session report']);
        foreach ([1, 2] as $revision) {
            $previous = 'clinical:v1: legacy collision report '.$revision;
            if ($corrupt && $revision === 2) {
                $payload = json_decode(base64_decode(Crypt::encryptString('Authenticated report')), true);
                $payload['mac'] = str_repeat('0', 64);
                $previous = 'clinical:v1:'.base64_encode(json_encode($payload));
            }
            DB::table('booking_report_revisions')->insert(['session_id' => $session->id, 'revision' => $revision,
                'actor_id' => $tu->id, 'previous_summary' => $previous, 'new_summary' => 'Legacy report '.$revision,
                'previous_sha256' => hash('sha256', $previous), 'new_sha256' => hash('sha256', 'Legacy report '.$revision), 'created_at' => now()]);
        }

        return [$session, DB::table('booking_report_revisions')->orderBy('id')->get()];
    }

    private function assertHistoryIsImmutable(int $id): void
    {
        try {
            DB::transaction(fn () => DB::table('booking_report_revisions')->where('id', $id)->update(['new_summary' => 'Tampered']));
            $this->fail('Immutable report controls must remain enforced.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_session_report_upgrade_encrypts_populated_history_preserves_hashes_and_immutability(): void
    {
        [$session, $before] = $this->legacySessionReports();
        $migration = require database_path('migrations/2026_10_03_220001_encrypt_clinical_session_reports.php');
        $migration->up();
        $this->assertSame('Legacy current session report', $session->fresh()->summary);
        $this->assertStringStartsWith('clinical:v1:', $session->fresh()->getRawOriginal('summary'));
        foreach ($before as $row) {
            $fresh = SessionReportRevision::findOrFail($row->id);
            $this->assertSame($row->previous_summary, $fresh->previous_summary);
            $this->assertSame($row->new_summary, $fresh->new_summary);
            $this->assertSame($row->new_sha256, $fresh->new_sha256);
            $this->assertSame($row->previous_sha256, $fresh->previous_sha256);
            $this->assertStringNotContainsString('legacy collision report', $fresh->getRawOriginal('previous_summary'));
            $this->assertStringNotContainsString('Legacy report', $fresh->getRawOriginal('new_summary'));
        }
        $ciphertext = DB::table('booking_report_revisions')->orderBy('id')->get()->toJson();
        $migration->up();
        $this->assertSame($ciphertext, DB::table('booking_report_revisions')->orderBy('id')->get()->toJson());
        $this->assertHistoryIsImmutable($before[0]->id);
    }

    public function test_session_report_failed_backfill_rolls_back_all_bodies_and_restores_immutability(): void
    {
        [$session, $before] = $this->legacySessionReports(true);
        try {
            (require database_path('migrations/2026_10_03_220001_encrypt_clinical_session_reports.php'))->up();
            $this->fail('Corrupt recognizable ciphertext must fail closed.');
        } catch (DecryptException) {
            $this->assertSame($before->toJson(), DB::table('booking_report_revisions')->orderBy('id')->get()->toJson());
            $this->assertSame('Legacy current session report', DB::table('therapy_sessions')->where('id', $session->id)->value('summary'));
        }
        $this->assertHistoryIsImmutable($before[0]->id);
    }

    public function test_populated_unique_parallel_layer_upgrade_preserves_content_and_enforces_constraint(): void
    {
        [$tu, $t] = $this->therapist();
        [$u, $p] = $this->patient($t);
        Schema::table('parallel_layers', fn ($table) => $table->dropUnique('clinical_parallel_patient_therapist_unique'));
        Schema::drop('clinical_erasure_plans');
        $layer = ParallelLayer::create(['id' => Str::uuid(), 'patient_id' => $u->id, 'therapist_id' => $tu->id,
            'content' => ['body' => 'Distinct clinical content remains intact'], 'edit_log' => []]);
        $original = $layer->getRawOriginal('content');
        (require database_path('migrations/2026_10_03_210003_clinical_erasure_and_parallel_uniqueness.php'))->up();
        $this->assertSame($original, $layer->fresh()->getRawOriginal('content'));
        $this->assertTrue(Schema::hasTable('clinical_erasure_plans'));
        $this->assertTrue(Schema::hasIndex('parallel_layers', 'clinical_parallel_patient_therapist_unique'));
        $this->expectException(UniqueConstraintViolationException::class);
        ParallelLayer::create(['id' => Str::uuid(), 'patient_id' => $u->id, 'therapist_id' => $tu->id, 'content' => []]);
    }

    public function test_shared_content_primary_erasure_preserves_remaining_assignee_access_without_leaking_erased_identity(): void
    {
        [$tu, $t] = $this->therapist();
        [$first, $p1] = $this->patient($t);
        [$remaining, $p2] = $this->patient($t);
        $service = app(TherapistContentService::class);
        $item = $service->create($t, ['title' => 'Shared exercise', 'content_type' => 'text',
            'body' => $first->name.'; Remaining patient exercise', 'patient_ids' => [$first->id, $remaining->id]]);
        app(AccountAnonymizer::class)->anonymize($first);
        $this->assertSame($remaining->id, $item->fresh()->patient_id);
        $this->assertSame([$remaining->id], $item->assignedPatients()->pluck('patients.user_id')->all());
        $this->assertStringNotContainsString($first->name, $item->fresh()->body);
        $service->update($t, $item->id, ['body' => 'Remaining patient exercise']);
        $this->actingAs($remaining, 'api');
        $this->assertStringContainsString('Remaining patient exercise', $this->getJson('/api/v1/patients/content')->assertOk()->getContent());
        $this->actingAs($first->fresh(), 'api');
        $this->getJson('/api/v1/patients/content')->assertForbidden();
    }

    public function test_compliance_excludes_sequence_locked_progress_even_with_an_active_subscription(): void
    {
        [$u, $p] = $this->patient();
        $modules = $this->modules(3);
        $subscription = Subscription::create(['patient_id' => $u->id, 'type' => '4_weeks',
            'price' => 0, 'verification_status' => 'approved', 'start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
        $p->update(['subscription_id' => $subscription->id]);
        foreach ($modules as $module) {
            PatientModule::create(['id' => Str::uuid(), 'patient_id' => $u->id, 'module_id' => $module->id, 'status' => 'pending']);
        }
        $state = app(ModuleAccessService::class)->stateFor($p, $modules[1]);
        $this->assertSame('previous_module_incomplete', $state['lock_reason']);
        $this->assertSame(1, app(ComplianceService::class)->snapshot($p, now()->addDay())['modules_due']);
    }

    public function test_emergency_second_channel_staging_failure_rolls_back_both_channels_and_retry_is_idempotent(): void
    {
        $staff = $this->user(UserRole::CLINICAL_SUPERVISOR);
        [$u, $p] = $this->patient();
        $event = ClinicalNotificationEvent::create(['patient_id' => $u->id, 'kind' => 'emergency_contact_requested', 'entity_id' => Str::uuid()]);
        $real = app(NotificationOutboxService::class);
        $outbox = Mockery::mock(NotificationOutboxService::class);
        $outbox->shouldReceive('stage')->andReturnUsing(function ($job, $key) use ($real) {
            if ($job instanceof SendWhatsAppMessageJob) {
                throw new RuntimeException('Injected WhatsApp staging failure.');
            }

            return $real->stage($job, $key);
        });
        $delivery = new ClinicalEventDelivery(new NotificationDispatcher($outbox));
        try {
            $delivery->deliver($event->id);
            $this->fail('The second channel staging failure must remain retryable.');
        } catch (RuntimeException) {
            $this->assertNull($event->fresh()->delivered_at);
            $this->assertSame(0, DB::table('notifications')->count());
            $this->assertSame(0, DB::table('notification_logs')->count());
            $this->assertSame(0, DB::table('ops_notification_outbox')->count());
        }
        $retry = new ClinicalEventDelivery(new NotificationDispatcher($real));
        $retry->deliver($event->id);
        $retry->deliver($event->id);
        $this->assertNotNull($event->fresh()->delivered_at);
        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertSame(2, DB::table('notification_logs')->count());
        $this->assertSame(2, DB::table('ops_notification_outbox')->count());
    }

    public function test_pending_erasure_plan_suppresses_delivery_even_if_owner_is_still_active(): void
    {
        $staff = $this->user(UserRole::CLINICAL_SUPERVISOR);
        [$u, $p] = $this->patient();
        $event = ClinicalNotificationEvent::create(['patient_id' => $u->id, 'kind' => 'emergency_contact_requested', 'entity_id' => Str::uuid()]);
        DB::table('clinical_erasure_plans')->insert(['user_id' => $u->id, 'paths' => '[]', 'directories' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        app(ClinicalEventDelivery::class)->deliver($event->id);
        $this->assertTrue($u->fresh()->is_active);
        $this->assertNull($event->fresh()->delivered_at);
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('notification_logs')->count());
        $this->assertSame(0, DB::table('ops_notification_outbox')->count());
    }

    private function rejectClinicalAuditInserts(): void
    {
        DB::unprepared(DB::getDriverName() === 'pgsql'
            ? "CREATE OR REPLACE FUNCTION clinical_reject_audit_insert() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Synthetic clinical audit rejection'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER clinical_reject_audit_insert BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION clinical_reject_audit_insert();"
            : "CREATE TRIGGER clinical_reject_audit_insert BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'Synthetic clinical audit rejection'); END;");
    }

    public static function contentAuditMutations(): array
    {
        return [['create'], ['update'], ['delete'], ['assign'], ['unassign']];
    }

    #[DataProvider('contentAuditMutations')]
    public function test_content_mutation_and_assignments_rollback_on_actual_audit_insert_rejection(string $operation): void
    {
        [$tu, $t] = $this->therapist();
        [$u1, $p1] = $this->patient($t);
        [$u2, $p2] = $this->patient($t);
        [$u3, $p3] = $this->patient($t);
        $service = app(TherapistContentService::class);
        $item = $service->create($t, ['title' => 'Safe original', 'content_type' => 'text', 'body' => 'Original body',
            'patient_ids' => [$u1->id, $u2->id]]);
        $beforeContent = DB::table('therapist_contents')->orderBy('id')->get()->toJson();
        $beforeAssignments = DB::table('therapist_content_assignments')->orderBy('id')->get()->toJson();
        $beforeAudit = DB::table('audit_logs')->count();
        $this->rejectClinicalAuditInserts();
        try {
            match ($operation) {
                'create' => $service->create($t, ['title' => 'Should roll back', 'content_type' => 'text', 'patient_id' => $u3->id]),
                'update' => $service->update($t, $item->id, ['body' => 'Should roll back']),
                'delete' => $service->delete($t, $item->id),
                'assign' => $service->assign($t, $item->id, [$u3->id]),
                'unassign' => $service->unassign($t, $item->id, $u1->id),
            };
            $this->fail('Rejected audit must roll back the clinical mutation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Synthetic clinical audit rejection', $e->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($beforeContent, DB::table('therapist_contents')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeAssignments, DB::table('therapist_content_assignments')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_switch_request_rolls_back_before_any_notification_on_actual_audit_rejection(): void
    {
        [$oldUser, $old] = $this->therapist();
        [$newUser, $new] = $this->therapist();
        [$u, $p] = $this->patient($old);
        $subscription = Subscription::create(['patient_id' => $u->id, 'therapist_id' => $oldUser->id,
            'type' => '4_weeks', 'price' => 0, 'verification_status' => 'approved', 'start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
        $this->rejectClinicalAuditInserts();
        try {
            app(TherapistSwitchService::class)->request($p, $newUser->id, 'Synthetic switch reason', $u);
            $this->fail('Rejected audit must roll back the switch request.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Synthetic clinical audit rejection', $e->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($oldUser->id, $p->fresh()->therapist_id);
        $this->assertTrue($subscription->fresh()->is_active);
        foreach (['therapist_switches', 'notifications', 'notification_logs', 'ops_notification_outbox', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    #[DataProvider('blockedPeriodAuditMutations')]
    public function test_blocked_period_mutation_rolls_back_on_actual_audit_insert_rejection(string $operation): void
    {
        [$tu, $t] = $this->therapist();
        $service = app(TherapistBlockedPeriodService::class);
        $period = $service->create($t, ['start_date' => now()->addDays(2)->toDateString(), 'reason' => 'Synthetic availability']);
        $before = DB::table('therapist_blocked_periods')->orderBy('id')->get()->toJson();
        $auditCount = DB::table('audit_logs')->count();
        $this->rejectClinicalAuditInserts();
        try {
            $operation === 'create'
                ? $service->create($t, ['start_date' => now()->addDays(4)->toDateString()])
                : $service->delete($t, $period->id);
            $this->fail('Rejected audit must roll back the availability mutation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Synthetic clinical audit rejection', $e->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, DB::table('therapist_blocked_periods')->orderBy('id')->get()->toJson());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public static function blockedPeriodAuditMutations(): array
    {
        return [['create'], ['delete']];
    }
}
