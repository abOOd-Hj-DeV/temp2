<?php

namespace Tests\Feature;

use App\Casts\ClinicalEncrypted;
use App\Jobs\PruneScheduledDeletionsJob;
use App\Models;
use App\Models\User;
use App\Services\Mood\MoodService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\NotificationService;
use App\Services\Patient\AccountAnonymizer;
use App\Services\Patient\ClinicalEncryption;
use App\Services\Patient\ComplianceService;
use App\Services\Patient\PatientAccountService;
use App\Services\Patient\PatientDashboardService;
use App\Services\RedFlagService;
use App\Services\Therapist\ParallelLayerService;
use App\Services\Therapist\TherapistClientService;
use App\Services\Therapist\TherapistModuleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

class ClinicalRepairRegressionTest extends TestCase
{
    use CommittedDatabase;

    private User $user;

    private Models\Patient $patient;

    private Models\Therapist $therapist;

    private Models\Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'sakina.uploads_disk' => 'local']);
        Storage::fake('local');
        // No providers or shared notification retry jobs run in this suite.
        $this->mock(NotificationService::class)->shouldIgnoreMissing();
        $this->seed(RolePermissionSeeder::class);
        $this->user = $this->user('patient');
        $tu = $this->user('therapist');
        $this->therapist = $this->therapist($tu);
        $this->patient = Models\Patient::create([
            'user_id' => $this->user->id, 'full_name' => $this->user->name, 'age' => 30,
            'gender' => 'female', 'language' => 'ar', 'therapist_id' => $tu->id,
        ]);
        $program = Models\Program::create(['id' => Str::uuid(), 'name' => 'Synthetic', 'description' => 'Synthetic only', 'is_core' => true]);
        $this->module = Models\Module::create([
            'id' => Str::uuid(), 'program_id' => $program->id, 'title' => 'Synthetic module', 'description' => 'd',
            'content_type' => 'text', 'order' => 1, 'is_hideable' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => 'Synthetic Identity '.Str::random(8), 'email' => Str::uuid().'@example.test',
            'whatsapp_number' => '+999'.random_int(100000000, 999999999), 'role' => $role,
            'password' => bcrypt('test-only-password'), 'is_active' => true, 'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function therapist(User $user): Models\Therapist
    {
        return Models\Therapist::create([
            'user_id' => $user->id, 'full_name' => $user->name, 'specialty' => 'cbt',
            'country' => 'DE', 'languages' => ['ar'], 'approval_status' => 'approved',
        ]);
    }

    private function mood(string $date, int $score, bool $alert = false): Models\MoodLog
    {
        return Models\MoodLog::create([
            'id' => Str::uuid(), 'patient_id' => $this->user->id, 'score' => $score,
            'log_date' => $date, 'alert_sent' => $alert, 'notes' => $this->user->name,
        ]);
    }

    private function flag(string $status = 'open'): Models\RedFlag
    {
        return Models\RedFlag::create([
            'patient_id' => $this->user->id, 'type' => 'safety', 'priority' => 'high',
            'description' => 'Old concern', 'status' => $status,
        ]);
    }

    public function test_patient_local_today_is_in_chart_history_and_live_episode_at_both_utc_boundaries(): void
    {
        foreach ([['Asia/Tokyo', '2026-10-03 23:30:00', '2026-10-04'], ['America/Los_Angeles', '2026-10-03 00:30:00', '2026-10-02']] as [$zone, $clock, $today]) {
            Models\MoodLog::where('patient_id', $this->user->id)->delete();
            Models\RedFlag::where('patient_id', $this->user->id)->delete();
            $this->travelTo(Carbon::parse($clock, 'UTC'));
            $this->user->forceFill(['timezone' => $zone])->save();
            $this->patient->unsetRelation('user');
            Sanctum::actingAs($this->user, ['*'], 'api');
            for ($i = 2; $i >= 0; $i--) {
                $date = Carbon::parse($today)->subDays($i)->toDateString();
                $this->postJson('/api/v1/patients/mood', ['score' => 2, 'log_date' => $date])->assertCreated();
                $this->travel(1)->seconds();
            }
            $chart = app(MoodService::class)->chart($this->patient, 3);
            $this->assertSame($today, $chart['series'][2]['date']);
            $this->assertSame(3, $chart['summary']['entries']);
            $this->assertSame(3, $chart['summary']['current_low_streak']);
            $this->assertCount(3, app(MoodService::class)->history($this->patient, 3)['entries']);
            $this->assertDatabaseCount('red_flags', 1);
            $this->postJson('/api/v1/patients/mood', ['score' => 2, 'log_date' => Carbon::parse($today)->addDay()->toDateString()])->assertUnprocessable();
        }
    }

    public function test_new_mood_episode_alert_is_not_suppressed_by_prior_episode_and_same_episode_does_not_spam(): void
    {
        $this->mood('2026-09-29', 2, true);
        $this->mood('2026-09-30', 8);
        $this->mood('2026-10-01', 2);
        $this->mood('2026-10-02', 2);
        $result = app(MoodService::class)->log($this->patient, ['score' => 2, 'log_date' => '2026-10-03']);
        $this->assertTrue($result['alert_raised']);
        $this->travel(1)->seconds();
        $again = app(MoodService::class)->log($this->patient, ['score' => 1, 'log_date' => '2026-10-03']);
        $this->assertFalse($again['alert_raised']);
        $this->assertDatabaseCount('red_flags', 1);
    }

    public function test_missed_day_recovery_and_backdated_checkin_do_not_create_a_live_alert(): void
    {
        $this->mood('2026-09-29', 2);
        $this->mood('2026-09-30', 2);
        $this->mood('2026-10-01', 8);
        $this->assertFalse(app(MoodService::class)->log($this->patient, ['score' => 2, 'log_date' => '2026-10-03'])['alert_raised']);
        $this->assertSame(1, app(MoodService::class)->chart($this->patient)['summary']['current_low_streak']);
        $this->assertDatabaseCount('red_flags', 0);
    }

    public function test_reopening_a_flag_conflicts_without_a_500_and_reopens_after_current_flag_is_resolved(): void
    {
        $old = $this->flag('resolved');
        $current = $this->flag();
        $admin = $this->user('admin');
        Sanctum::actingAs($admin, ['*'], 'api');
        $this->postJson("/api/v1/admin/red-flags/{$old->id}/status", ['status' => 'open'])->assertConflict();
        $this->assertSame('resolved', $old->refresh()->status);
        $this->assertSame('open', $current->refresh()->status);
        app(RedFlagService::class)->updateStatus($current->id, 'resolved', 'Synthetic resolution');
        $this->assertTrue(app(RedFlagService::class)->updateStatus($old->id, 'open'));
        $this->assertSame('open', $old->refresh()->status);
    }

    public function test_hidden_modules_do_not_reduce_compliance_and_unhiding_restores_due_work(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->mood(now()->subDays($i)->toDateString(), 8);
        }
        Models\PatientModule::create(['id' => Str::uuid(), 'patient_id' => $this->user->id, 'module_id' => $this->module->id,
            'status' => 'pending', 'hidden_at' => now()->subDay()])->forceFill(['created_at' => now()->subDay()])->save();
        $snapshot = app(ComplianceService::class)->snapshot($this->patient, now());
        $this->assertSame(0, $snapshot['modules_due']);
        $this->assertSame(100, $snapshot['score']);
        app(ComplianceService::class)->apply($this->patient, now());
        $this->assertDatabaseCount('red_flags', 0);
        app(TherapistModuleService::class)->unhide($this->therapist, $this->user->id, $this->module->id);
        $this->assertSame(1, app(ComplianceService::class)->snapshot($this->patient, now())['modules_due']);
    }

    public function test_former_therapist_keeps_history_but_cannot_mutate_current_care(): void
    {
        $layer = app(ParallelLayerService::class)->save($this->therapist, $this->user->id, ['plan' => 'Historical']);
        Models\TherapySession::create(['patient_id' => $this->user->id, 'therapist_id' => $this->therapist->user_id,
            'session_date' => '2026-10-01', 'session_time' => '12:00', 'medium' => 'meet', 'status' => 'completed', 'price' => 0]);
        $newTherapist = $this->therapist($this->user('therapist'));
        $this->patient->update(['therapist_id' => $newTherapist->user_id]);
        $this->assertSame($layer->id, app(ParallelLayerService::class)->get($this->therapist, $this->user->id)->id);
        $this->assertNotEmpty(app(TherapistClientService::class)->show($this->therapist, $this->user->id)['sessions']);
        foreach ([
            fn () => app(ParallelLayerService::class)->save($this->therapist, $this->user->id, ['plan' => 'Unauthorized']),
            fn () => app(TherapistModuleService::class)->hide($this->therapist, $this->user->id, $this->module->id),
            fn () => app(TherapistModuleService::class)->unhide($this->therapist, $this->user->id, $this->module->id),
            fn () => app(TherapistClientService::class)->addNote($this->therapist, $this->user->id, 'Unauthorized'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Former therapist must not mutate current care');
            } catch (NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }
        app(TherapistModuleService::class)->hide($newTherapist, $this->user->id, $this->module->id);
        app(ParallelLayerService::class)->save($newTherapist, $this->user->id, ['plan' => 'Authorized']);
        $this->assertSame(['plan' => 'Historical'], $layer->refresh()->content);
    }

    public function test_parallel_stale_instances_preserve_all_logs_and_committed_edits_notify_once_each(): void
    {
        $service = app(ParallelLayerService::class);
        $layer = $service->save($this->therapist, $this->user->id, ['plan' => 'one']);
        $service->save($this->therapist, $this->user->id, ['plan' => 'two']);
        $a = $layer->fresh();
        $b = $layer->fresh();
        $a->update(['content' => ['plan' => 'a']]);
        $b->update(['content' => ['plan' => 'b']]);
        $a->addEditLog('a', []);
        $b->addEditLog('b', []);
        $this->assertCount(4, $layer->refresh()->edit_log);
        $this->assertSame(['created', 'updated', 'a', 'b'], array_column($layer->edit_log, 'action'));
        $this->assertSame(2, $this->user->notifications()->count());
        $this->artisan('clinical:deliver-notifications')->assertSuccessful();
        $this->assertSame(2, $this->user->notifications()->count());
        $this->assertStringNotContainsString('plan', json_encode($this->user->notifications()->get()->pluck('data')));
    }

    public function test_parallel_outbox_survives_delivery_failure_and_transaction_rollback_creates_no_event(): void
    {
        $this->mock(NotificationDispatcher::class)->shouldReceive('inApp')->andThrow(new \RuntimeException('Synthetic delivery failure'));
        $layer = app(ParallelLayerService::class)->save($this->therapist, $this->user->id, ['plan' => 'Durable']);
        $this->assertDatabaseCount('clinical_notification_events', 1);
        $this->assertSame(0, $this->user->notifications()->count());
        $this->assertNull(Models\ClinicalNotificationEvent::first()->delivered_at);
        $this->app->forgetInstance(NotificationDispatcher::class);
        $this->artisan('clinical:deliver-notifications')->assertSuccessful();
        $this->artisan('clinical:deliver-notifications')->assertSuccessful();
        $this->assertSame(1, $this->user->notifications()->count());
        try {
            DB::transaction(function () {
                app(ParallelLayerService::class)->save($this->therapist, $this->user->id, ['plan' => 'Rolled back']);
                throw new \RuntimeException('Synthetic rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(['plan' => 'Durable'], $layer->refresh()->content);
        $this->assertDatabaseCount('clinical_notification_events', 1);
        $this->assertSame(1, $this->user->notifications()->count());
    }

    public function test_emergency_request_always_persists_a_fresh_in_app_escalation_even_with_high_open_flag(): void
    {
        $staff = $this->user('clinical_supervisor');
        $flag = $this->flag();
        app(PatientDashboardService::class)->emergencyAlert($this->user);
        app(PatientDashboardService::class)->emergencyAlert($this->user);
        $this->assertDatabaseCount('red_flags', 1);
        $this->assertTrue($this->patient->refresh()->safety_flag);
        $this->assertSame('Old concern', $flag->refresh()->description);
        $this->assertSame(2, $staff->notifications()->count());
        $this->assertDatabaseCount('clinical_notification_events', 2);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'patient.emergency_contact_requested')->count());
    }

    public function test_emergency_outbox_remains_pending_without_staff_and_replays_when_staff_exists(): void
    {
        $result = app(PatientDashboardService::class)->emergencyAlert($this->user);
        $this->assertSame('pending', $result['escalation']['status']);
        $this->assertNull(Models\ClinicalNotificationEvent::first()->delivered_at);
        $this->artisan('clinical:deliver-notifications')->assertFailed();
        $staff = $this->user('clinical_supervisor');
        $this->artisan('clinical:deliver-notifications')->assertSuccessful();
        $this->assertSame(1, $staff->notifications()->count());
    }

    private function inventory(): array
    {
        $marker = $this->user->name;
        $id = $this->user->id;
        $subscription = Models\Subscription::create(['patient_id' => $id, 'type' => '4_weeks', 'price' => 0,
            'content' => ['clinical_note' => $marker], 'cancellation_reason' => $marker,
            'payment_proof_path' => "payment-proofs/{$id}/subscription.pdf"]);
        $switch = Models\TherapistSwitch::create(['id' => Str::uuid(), 'patient_id' => $id,
            'old_therapist_id' => $this->therapist->user_id, 'new_therapist_id' => $this->therapist->user_id,
            'subscription_id' => $subscription->id, 'reason' => $marker, 'status' => 'approved']);
        $assessment = Models\Assessment::create(['id' => Str::uuid(), 'patient_id' => $id, 'type' => 'phq9',
            'score' => 1, 'answers' => ['q1' => 1], 'completed_at' => now()]);
        $ticket = Models\Support::create(['id' => Str::uuid(), 'user_id' => $id, 'type' => 'technical', 'subject' => $marker, 'description' => $marker,
            'status' => 'open', 'file_path' => "uploads/{$id}/support/proof.txt"]);
        $reply = Models\SupportReply::create(['support_id' => $ticket->id, 'user_id' => $id, 'body' => $marker]);
        $homework = Models\PatientModule::create(['id' => Str::uuid(), 'patient_id' => $id, 'module_id' => $this->module->id,
            'status' => 'pending', 'homework' => ['answer' => $marker]]);
        $safety = Models\SafetyPlan::create(['id' => Str::uuid(), 'patient_id' => $id, 'contact_info' => ['phone' => '+999123456789', 'name' => $marker],
            'emergency_contacts' => $marker, 'coping_strategies' => $marker, 'warning_signs' => $marker]);
        $mood = $this->mood('2026-10-03', 8);
        $mood->update(['anxiety' => 3, 'energy' => 4, 'sleep_hours' => 7.5, 'activity_level' => 2]);
        $layer = app(ParallelLayerService::class)->save($this->therapist, $id, ['plan' => $marker]);
        $note = app(TherapistClientService::class)->addNote($this->therapist, $id, $marker);
        $session = Models\TherapySession::create(['patient_id' => $id, 'therapist_id' => $this->therapist->user_id,
            'session_date' => '2026-10-01', 'session_time' => '12:00', 'medium' => 'meet', 'status' => 'completed', 'price' => 0, 'summary' => $marker]);
        $payment = Models\Payment::create(['therapy_session_id' => $session->id, 'amount' => 0, 'status' => 'approved',
            'proof_file_path' => "payment-proofs/{$id}/proof.txt", 'note' => $marker]);
        $document = Models\DocumentRequest::create(['user_id' => $id, 'doc_type' => 'other', 'reason' => $marker,
            'status' => 'submitted', 'file_path' => "uploads/{$id}/other/document.txt", 'original_name' => $marker.'.txt']);
        $review = Models\Review::create(['id' => Str::uuid(), 'patient_id' => $id, 'therapist_id' => $this->therapist->user_id, 'rating' => 4, 'comment' => $marker]);
        $recommendation = Models\SessionRecommendation::create(['session_id' => $session->id, 'patient_id' => $id, 'therapist_id' => $this->therapist->user_id, 'note' => $marker]);
        $content = Models\TherapistContent::create(['id' => Str::uuid(), 'patient_id' => $id, 'therapist_id' => $this->therapist->user_id,
            'title' => $marker, 'content_type' => 'text', 'body' => $marker]);
        $content->assignedPatients()->attach($id, ['id' => Str::uuid(), 'assigned_at' => now()]);
        $conversation = Models\Conversation::create(['patient_id' => $id, 'therapist_id' => $this->therapist->user_id, 'status' => 'active']);
        $message = Models\Message::create(['conversation_id' => $conversation->id, 'sender_id' => $id, 'receiver_id' => $this->therapist->user_id,
            'content' => $marker, 'file_path' => "chat/{$conversation->id}/message.txt", 'timestamp' => now()]);
        Models\IdempotencyKey::create(['user_id' => $id, 'key' => Str::uuid(), 'route' => 'synthetic', 'request_hash' => hash('sha256', 'test'),
            'response_body' => json_encode(['marker' => $marker]), 'locked_at' => now(), 'expires_at' => now()->addDay()]);
        foreach ([$ticket->file_path, $payment->proof_file_path, $subscription->payment_proof_path, $document->file_path, $message->file_path, "uploads/{$id}/other/orphan.txt"] as $path) {
            Storage::disk('local')->put($path, $marker);
        }

        return compact('ticket', 'reply', 'homework', 'safety', 'mood', 'layer', 'note', 'session', 'payment', 'document', 'review', 'recommendation', 'content', 'conversation', 'message', 'subscription', 'switch', 'assessment');
    }

    public function test_clinical_fields_are_ciphertext_in_raw_database_with_legitimate_model_roundtrip(): void
    {
        $inventory = $this->inventory();
        $flag = $this->flag();
        $flag->update(['description' => $this->user->name, 'action_taken' => $this->user->name]);
        foreach ($inventory + ['flag' => $flag] as $model) {
            foreach (ClinicalEncryption::FIELDS[get_class($model)] ?? [] as $column) {
                $raw = DB::table($model->getTable())->where('id', $model->id)->value($column);
                if ($raw === null) {
                    continue;
                }
                $this->assertStringStartsWith(ClinicalEncrypted::PREFIX, $raw, $model->getTable().'.'.$column);
                $this->assertStringNotContainsString($this->user->name, $raw);
                $this->assertSame($model->{$column}, $model->fresh()->{$column});
            }
        }
    }

    public function test_legacy_text_json_backfill_and_rotation_are_lossless_and_corruption_fails_closed(): void
    {
        $row = $this->mood('2026-10-03', 8);
        DB::table('mood_logs')->where('id', $row->id)->update(['notes' => 'Legacy marker']);
        $homework = Models\PatientModule::create(['id' => Str::uuid(), 'patient_id' => $this->user->id, 'module_id' => $this->module->id, 'status' => 'pending']);
        DB::table('patient_modules')->where('id', $homework->id)->update(['homework' => json_encode(['answer' => 'Legacy JSON marker'])]);
        $this->assertSame('Legacy marker', $row->fresh()->notes);
        $this->assertSame(['answer' => 'Legacy JSON marker'], $homework->fresh()->homework);
        $this->artisan('clinical:encrypt-text')->assertSuccessful();
        $raw = $row->fresh()->getRawOriginal('notes');
        $this->assertStringStartsWith(ClinicalEncrypted::PREFIX, $raw);
        $this->artisan('clinical:encrypt-text')->assertSuccessful();
        $this->assertSame($raw, $row->fresh()->getRawOriginal('notes'));
        $oldKey = app('encrypter')->getKey();
        $newKey = random_bytes(32);
        $rotating = new Encrypter($newKey, 'AES-256-CBC');
        $rotating->previousKeys([$oldKey]);
        $this->app->instance('encrypter', $rotating);
        Crypt::clearResolvedInstance('encrypter');
        $this->assertSame('Legacy marker', $row->fresh()->notes);
        $this->artisan('clinical:encrypt-text', ['--rotate' => true])->assertSuccessful();
        $this->assertNotSame($raw, $row->fresh()->getRawOriginal('notes'));
        $currentOnly = new Encrypter($newKey, 'AES-256-CBC');
        $this->assertSame('Legacy marker', $currentOnly->decryptString(substr($row->fresh()->getRawOriginal('notes'), strlen(ClinicalEncrypted::PREFIX))));
        $this->assertSame('Legacy marker', $row->fresh()->notes);
        $this->assertSame(['answer' => 'Legacy JSON marker'], $homework->fresh()->homework);
        DB::table('mood_logs')->where('id', $row->id)->update(['notes' => ClinicalEncrypted::PREFIX.'corrupt']);
        $this->expectException(DecryptException::class);
        $row->fresh()->notes;
    }

    public function test_export_inventory_contains_patient_visible_families_axes_and_messages_without_private_notes(): void
    {
        $inventory = $this->inventory();
        $export = app(PatientAccountService::class)->exportData($this->user);
        $this->assertSame(2, $export['schema_version']);
        $this->assertSame($this->user->name, $export['support_tickets'][0]['description']);
        $this->assertSame($this->user->name, $export['support_tickets'][0]['replies'][0]['body']);
        $this->assertSame($this->user->name, $export['patient_modules'][0]['homework']['answer']);
        $this->assertSame($this->user->name, $export['safety_plans'][0]['contact_info']['name']);
        $this->assertSame($this->user->name, $export['conversations'][0]['messages'][0]['content']);
        $this->assertSame(3, $export['mood_logs'][0]['anxiety']);
        $this->assertSame(4, $export['mood_logs'][0]['energy']);
        $this->assertSame(7.5, $export['mood_logs'][0]['sleep_hours']);
        $this->assertSame(2, $export['mood_logs'][0]['activity_level']);
        foreach (['document_requests', 'session_recommendations', 'reviews', 'therapist_contents', 'payments', 'sessions'] as $section) {
            $this->assertCount(1, $export[$section], $section);
        }
        $this->assertCount(5, $export['files']);
        $this->assertSame($this->user->name, $export['subscriptions'][0]['content']['clinical_note']);
        $this->assertSame($this->user->name, $export['therapist_switches'][0]['reason']);
        $this->assertSame(['q1' => 1], $export['assessments'][0]['answers']);
        $this->assertArrayHasKey('parallel_layers', $export['export_scope']['excluded']);
        $this->assertArrayHasKey('therapist_client_notes', $export['export_scope']['excluded']);
        $this->assertArrayNotHasKey('password', $export['user']);
    }

    public function test_erasure_scrubs_inventory_deletes_every_owned_file_family_and_preserves_append_only_audit(): void
    {
        $inventory = $this->inventory();
        $name = $this->user->name;
        $this->user->createToken('Synthetic');
        $auditCount = DB::table('audit_logs')->count();
        app(AccountAnonymizer::class)->anonymize($this->user);
        $user = $this->user->fresh();
        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->anonymized_at);
        $this->assertNull($user->deletion_scheduled_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertDatabaseCount('clinical_notification_events', 0);
        $this->assertDatabaseCount('safety_plans', 0);
        $this->assertDatabaseCount('therapist_content_assignments', 0);
        $this->assertNull($inventory['homework']->fresh()->homework);
        $this->assertNull($inventory['mood']->fresh()->notes);
        $this->assertNull($inventory['ticket']->fresh()->file_path);
        $this->assertNull($inventory['document']->fresh()->original_name);
        $this->assertSame($auditCount + 1, DB::table('audit_logs')->count());
        foreach (['ticket', 'reply', 'layer', 'note', 'session', 'payment', 'document', 'review', 'recommendation', 'content', 'subscription', 'switch'] as $key) {
            $this->assertStringNotContainsString($name, json_encode($inventory[$key]->fresh()->toArray()), $key);
        }
        $export = app(PatientAccountService::class)->exportData($user);
        $this->assertStringNotContainsString($name, json_encode($export));
        $this->assertSame([], $export['files']);
        app(AccountAnonymizer::class)->anonymize($user);
        $this->assertSame($auditCount + 1, DB::table('audit_logs')->count());
    }

    public function test_partial_file_failure_keeps_account_disabled_and_scheduled_job_resumes_without_false_restoration(): void
    {
        $inventory = $this->inventory();
        $disk = Storage::disk('local');
        $failedPath = $inventory['ticket']->file_path;
        $injectFailure = true;
        $wrapper = Mockery::mock(FilesystemAdapter::class);
        $wrapper->shouldReceive('exists')->andReturnUsing(fn ($path) => $disk->exists($path));
        $wrapper->shouldReceive('allFiles')->andReturnUsing(fn ($path) => $disk->allFiles($path));
        $wrapper->shouldReceive('deleteDirectory')->andReturnUsing(fn ($path) => $disk->deleteDirectory($path));
        $wrapper->shouldReceive('delete')->andReturnUsing(function ($path) use ($disk, $failedPath, &$injectFailure) {
            if ($injectFailure && $path === $failedPath) {
                throw new \RuntimeException('Synthetic later file failure');
            }

            return $disk->delete($path);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($wrapper);
        try {
            app(AccountAnonymizer::class)->anonymize($this->user);
            $this->fail('Injected file failure must remain retryable');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic later file failure', $e->getMessage());
        }
        $user = $this->user->fresh();
        $this->assertFalse($user->is_active);
        $this->assertSame('Deleted user', $user->name);
        $this->assertNull($user->anonymized_at);
        $this->assertNotNull($user->deletion_scheduled_at);
        $this->assertFalse($disk->exists($inventory['payment']->proof_file_path));
        $this->assertTrue($disk->exists($failedPath));
        $this->assertDatabaseCount('clinical_erasure_plans', 1);
        $injectFailure = false;
        (new PruneScheduledDeletionsJob)->handle(app(AccountAnonymizer::class));
        $this->assertNotNull($user->fresh()->anonymized_at);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame([], $disk->allFiles());
    }

    public function test_erasure_rejects_enclosing_transaction_before_any_irreversible_action(): void
    {
        Storage::disk('local')->put("uploads/{$this->user->id}/proof.pdf", 'Synthetic');
        try {
            DB::transaction(fn () => app(AccountAnonymizer::class)->anonymize($this->user));
            $this->fail('An enclosing transaction could falsely restore an account after file deletion');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('outside an enclosing transaction', $e->getMessage());
        }
        $this->assertTrue($this->user->fresh()->is_active);
        $this->assertDatabaseCount('clinical_erasure_plans', 0);
        Storage::disk('local')->assertExists("uploads/{$this->user->id}/proof.pdf");
    }

    public function test_upgrade_encrypts_legacy_json_and_plaintext_without_losing_unique_safety_controls(): void
    {
        $baseline = array_values(array_filter(glob(database_path('migrations/*.php')),
            fn ($path) => ! str_starts_with(basename($path), '2026_10_03_21000')));
        $this->artisan('migrate:fresh', ['--path' => $baseline, '--realpath' => true])->assertSuccessful();
        $this->seed(RolePermissionSeeder::class);
        $patientUser = $this->user('patient');
        $therapist = $this->therapist($this->user('therapist'));
        Models\Patient::create(['user_id' => $patientUser->id, 'full_name' => $patientUser->name,
            'age' => 30, 'gender' => 'female', 'language' => 'ar']);
        $layerId = (string) Str::uuid();
        DB::table('parallel_layers')->insert(['id' => $layerId, 'patient_id' => $patientUser->id,
            'therapist_id' => $therapist->user_id, 'content' => json_encode(['legacy' => 'Sensitive synthetic']),
            'edit_log' => json_encode([['action' => 'created']]), 'created_at' => now(), 'updated_at' => now()]);
        $supportId = (string) Str::uuid();
        DB::table('supports')->insert(['id' => $supportId, 'user_id' => $patientUser->id, 'type' => 'technical',
            'subject' => 'Sensitive synthetic', 'description' => 'Sensitive synthetic', 'status' => 'open']);
        $this->artisan('migrate')->assertSuccessful();
        $this->assertSame(['legacy' => 'Sensitive synthetic'], Models\ParallelLayer::findOrFail($layerId)->content);
        $this->assertSame([['action' => 'created']], Models\ParallelLayer::findOrFail($layerId)->edit_log);
        $this->assertSame('Sensitive synthetic', Models\Support::findOrFail($supportId)->description);
        foreach (['content', 'edit_log'] as $column) {
            $this->assertStringStartsWith(ClinicalEncrypted::PREFIX, DB::table('parallel_layers')->where('id', $layerId)->value($column));
        }
        $this->assertStringStartsWith(ClinicalEncrypted::PREFIX, DB::table('supports')->where('id', $supportId)->value('description'));
        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame('text', DB::table('information_schema.columns')->where('table_schema', 'public')
                ->where('table_name', 'parallel_layers')->where('column_name', 'content')->value('data_type'));
        }
        $definition = DB::getDriverName() === 'pgsql'
            ? DB::table('pg_indexes')->where('indexname', 'red_flags_open_per_patient_type_unique')->value('indexdef')
            : DB::table('sqlite_master')->where('name', 'red_flags_open_per_patient_type_unique')->value('sql');
        $this->assertStringContainsString('WHERE', $definition);
        $this->assertStringContainsString('open', $definition);
    }
}
