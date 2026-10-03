<?php

namespace Tests\Feature;

use App\Jobs\BroadcastStoredNotificationJob;
use App\Jobs\DeliverNotificationOutboxJob;
use App\Jobs\ReviewPaymentProofJob;
use App\Jobs\SendSessionRemindersJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\NotificationOutbox;
use App\Models\Package;
use App\Models\Patient;
use App\Models\RedFlag;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Notifications\StoredNotificationAvailable;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationOutboxService;
use App\Services\Notifications\OperationsHealth;
use App\Services\NotificationService;
use App\Services\Session\SessionService;
use App\Services\Subscription\SubscriptionService;
use App\Support\SessionClock;
use Composer\InstalledVersions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Mockery;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

class OpsRepairRegressionTest extends TestCase
{
    use CommittedDatabase;

    private User $patientUser;

    private User $therapistUser;

    private Patient $patient;

    private FakeWhatsAppSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 10:00:00', 'UTC'));
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'queue.default' => 'sync']);
        $this->app->forgetInstance('encrypter');
        $this->seed(RolePermissionSeeder::class);
        $this->sender = new FakeWhatsAppSender;
        $this->app->instance(WhatsAppSenderInterface::class, $this->sender);
        $this->patientUser = $this->user('patient');
        $this->therapistUser = $this->user('therapist');
        Therapist::create([
            'user_id' => $this->therapistUser->id, 'full_name' => 'Synthetic therapist',
            'specialty' => 'cbt', 'country' => 'JO', 'approval_status' => 'approved',
            'availability' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['09:00-18:00']),
        ]);
        $this->patient = Patient::create([
            'user_id' => $this->patientUser->id, 'full_name' => 'Synthetic patient',
            'age' => 30, 'gender' => 'other', 'language' => 'en', 'therapist_id' => $this->therapistUser->id,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => 'Synthetic '.$role, 'email' => Str::uuid().'@example.invalid',
            'password' => 'test-only-password', 'whatsapp_number' => '+9639'.random_int(10000000, 99999999),
            'role' => $role, 'is_active' => true, 'phone_verified_at' => now(), 'timezone' => 'UTC',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function appointment(): TherapySession
    {
        return TherapySession::create([
            'patient_id' => $this->patientUser->id, 'therapist_id' => $this->therapistUser->id,
            'session_date' => now()->addDay()->toDateString(), 'session_time' => '09:30',
            'medium' => 'meet', 'status' => 'confirmed', 'payment_status' => 'paid', 'price' => 0, 'is_initial' => true,
        ]);
    }

    private function queueOutage(): void
    {
        config(['queue.default' => 'redis', 'database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);
        app('redis')->purge('default');
    }

    public function test_critical_channel_survives_real_queue_outage_and_replays_without_duplicate_in_app(): void
    {
        $supervisor = $this->user('clinical_supervisor');
        $flag = RedFlag::create([
            'patient_id' => $this->patientUser->id, 'type' => 'safety', 'priority' => 'high',
            'status' => 'open', 'assigned_to' => $supervisor->id, 'description' => 'Synthetic safety signal',
        ]);
        $this->queueOutage();
        $notifications = app(NotificationService::class);
        $notifications->redFlagRaised($flag);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(2, NotificationLog::where('channel', 'whatsapp')->where('status', 'queued')->count());
        $this->assertSame(4, NotificationOutbox::where('status', 'pending')->count());
        $this->assertCount(0, $this->sender->messages);

        $notifications->redFlagRaised($flag);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('ops_notification_outbox', 4);
        $this->assertDatabaseCount('notification_logs', 4);

        config(['queue.default' => 'sync']);
        $this->travel(31)->seconds();
        app(NotificationOutboxService::class)->replay();
        $this->assertSame(2, NotificationLog::where('channel', 'whatsapp')->where('status', 'sent')->count());
        $this->assertCount(2, $this->sender->messages);
        $this->assertSame(0, NotificationOutbox::whereNull('completed_at')->count());
        $notifications->redFlagRaised($flag);
        $this->assertCount(2, $this->sender->messages);
    }

    public function test_proof_and_outbox_commit_together_despite_closed_redis_port(): void
    {
        Storage::fake('ops_proofs');
        config(['sakina.uploads_disk' => 'ops_proofs']);
        $package = Package::create([
            'code' => 'ops_synthetic', 'name' => 'Synthetic package', 'price' => 150,
            'number_of_sessions' => 4, 'duration_days' => 28, 'daily_sessions_quota' => 1, 'is_published' => true,
        ]);
        $this->queueOutage();
        $result = app(SubscriptionService::class)->createWithProof($this->patient, $package->id, UploadedFile::fake()->create('proof.pdf', 1, 'application/pdf'));
        Storage::disk('ops_proofs')->assertExists($result['payment']->proof_file_path);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('ops_notification_outbox', 1);
        $this->assertSame(0, DB::transactionLevel());

        $finance = $this->user('finance_partner');
        config(['queue.default' => 'sync']);
        $this->travel(31)->seconds();
        app(NotificationOutboxService::class)->replay();
        $this->assertSame(1, $finance->notifications()->count());
        Storage::disk('ops_proofs')->assertExists($result['payment']->proof_file_path);
    }

    public function test_rollback_discards_outbox_without_queueing(): void
    {
        Queue::fake();
        try {
            DB::transaction(function (): void {
                ReviewPaymentProofJob::dispatch((string) Str::uuid())->afterCommit();
                $this->assertDatabaseCount('ops_notification_outbox', 1);
                throw new \RuntimeException('Synthetic rollback.');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('ops_notification_outbox', 0);
        Queue::assertNothingPushed();
    }

    public function test_proposed_rollback_only_cleanup_preserves_proof_after_callback_failure(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('synthetic-proof', 'synthetic data');
        $this->queueOutage();
        try {
            DB::transaction(function (): void {
                DB::afterRollBack(fn () => Storage::disk('local')->delete('synthetic-proof'));
                ReviewPaymentProofJob::dispatch((string) Str::uuid())->afterCommit();
                DB::afterCommit(fn () => throw new \RuntimeException('Synthetic post-commit failure.'));
            });
            $this->fail('The adversarial callback must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic post-commit failure.', $e->getMessage());
        }
        Storage::disk('local')->assertExists('synthetic-proof');
        $this->assertDatabaseCount('ops_notification_outbox', 1);
        $this->assertSame(0, DB::transactionLevel());

        Storage::disk('local')->put('synthetic-rolled-back-proof', 'synthetic data');
        try {
            DB::transaction(function (): void {
                DB::afterRollBack(fn () => Storage::disk('local')->delete('synthetic-rolled-back-proof'));
                ReviewPaymentProofJob::dispatch((string) Str::uuid())->afterCommit();
                throw new \RuntimeException('Synthetic rollback.');
            });
        } catch (\RuntimeException) {
        }
        Storage::disk('local')->assertMissing('synthetic-rolled-back-proof');
        $this->assertDatabaseCount('ops_notification_outbox', 1);
    }

    public function test_real_database_worker_recovers_queue_outage_and_emits_heartbeat(): void
    {
        $this->queueOutage();
        $row = app(NotificationOutboxService::class)->stage(new SendWhatsAppMessageJob('+15550000000', 'synthetic worker delivery'));
        $this->assertSame('queue_unavailable', $row->fresh()->last_error);

        config(['queue.default' => 'database', 'cache.default' => 'array']);
        $this->travel(31)->seconds();
        $this->artisan('ops:outbox-replay')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1])->assertSuccessful();

        $this->assertCount(1, $this->sender->messages);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('completed', $row->fresh()->status);
        $this->assertTrue(app(OperationsHealth::class)->heartbeatFresh('worker'));
    }

    public function test_outbox_encrypts_message_and_ledgers_reject_secret_context(): void
    {
        Queue::fake();
        app(NotificationDispatcher::class)->whatsApp($this->patientUser, 'Synthetic OTP 781234 secret-token', 'ops.private:1', ['token' => 'secret-token', 'phone' => $this->patientUser->whatsapp_number]);
        $raw = DB::table('ops_notification_outbox')->value('payload');
        $this->assertStringNotContainsString('781234', $raw);
        $this->assertStringNotContainsString($this->patientUser->whatsapp_number, $raw);
        $this->assertNull(NotificationLog::sole()->context);
        Queue::assertPushed(DeliverNotificationOutboxJob::class, function ($job): bool {
            return ! str_contains(serialize($job), 'secret-token') && ! str_contains(serialize($job), '781234');
        });
        $this->assertArrayNotHasKey('payload', NotificationOutbox::sole()->toArray());
    }

    public function test_leases_exclude_other_claims_and_recover_dead_worker_without_accepting_stale_token(): void
    {
        Queue::fake();
        app(NotificationDispatcher::class)->whatsApp($this->patientUser, 'Synthetic message', 'ops.claim:1');
        $row = NotificationOutbox::sole();
        $oldToken = $row->claim_token;
        app(NotificationOutboxService::class)->enqueue($row->id);
        Queue::assertPushed(DeliverNotificationOutboxJob::class, 1);
        $this->travel(301)->seconds();
        app(NotificationOutboxService::class)->replay();
        Queue::assertPushed(DeliverNotificationOutboxJob::class, 2);
        $newToken = $row->fresh()->claim_token;
        $this->assertNotSame($oldToken, $newToken);
        app(NotificationOutboxService::class)->execute($row->id, $oldToken);
        $this->assertCount(0, $this->sender->messages);
        app(NotificationOutboxService::class)->execute($row->id, $newToken);
        app(NotificationOutboxService::class)->execute($row->id, $newToken);
        $this->assertCount(1, $this->sender->messages);
        $this->assertNull($row->fresh()->payload);
    }

    public function test_provider_failure_uses_backoff_and_fixed_error_without_sensitive_exception_text(): void
    {
        $sender = Mockery::mock(WhatsAppSenderInterface::class);
        $sender->shouldReceive('send')->once()->andThrow(new \RuntimeException('secret-token phone-number'));
        $this->app->instance(WhatsAppSenderInterface::class, $sender);
        app(NotificationDispatcher::class)->whatsApp($this->patientUser, 'Synthetic message', 'ops.provider:1');
        $row = NotificationOutbox::sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame('delivery_failed', $row->last_error);
        $this->assertSame(now()->addSeconds(30)->timestamp, $row->available_at->timestamp);
        $this->assertSame('Delivery rejected or unavailable; retry pending.', NotificationLog::sole()->error);
        app(NotificationOutboxService::class)->replay();
        $this->assertSame(1, $row->fresh()->attempts);
        $this->app->instance(WhatsAppSenderInterface::class, $this->sender);
        $this->travel(31)->seconds();
        app(NotificationOutboxService::class)->replay();
        $this->assertSame('sent', NotificationLog::sole()->status);
        $this->assertSame('completed', $row->fresh()->status);
    }

    public function test_notification_event_failure_remains_durable_without_working_queue(): void
    {
        $session = $this->appointment();
        $dispatcher = Mockery::mock(NotificationDispatcher::class);
        $dispatcher->shouldReceive('inApp')->andThrow(new \RuntimeException('Synthetic outage.'));
        $this->app->instance(NotificationService::class, new NotificationService($dispatcher));
        $this->queueOutage();
        app(NotificationService::class)->deliver('sessionBooked', $session);
        $this->assertSame('pending', NotificationOutbox::sole()->status);
        $this->app->forgetInstance(NotificationService::class);
        config(['queue.default' => 'sync']);
        $this->travel(31)->seconds();
        app(NotificationOutboxService::class)->replay();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(0, NotificationOutbox::whereNull('completed_at')->count());
    }

    public function test_reminder_dedupe_and_counters_follow_new_appointment_instead_of_old_flags(): void
    {
        $session = $this->appointment();
        $job = new SendSessionRemindersJob;
        $job->handle(app(SessionRepositoryInterface::class), app(NotificationService::class));
        $this->travel(35)->seconds();
        $job->handle(app(SessionRepositoryInterface::class), app(NotificationService::class));
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('ops_session_reminders', 1);
        $session->refresh()->update(['session_date' => now()->addDays(3)->toDateString(), 'reminder_attempts' => 3, 'reminder_sent' => true]);
        $this->travel(2)->days();
        $job->handle(app(SessionRepositoryInterface::class), app(NotificationService::class));
        $job->handle(app(SessionRepositoryInterface::class), app(NotificationService::class));
        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseCount('ops_session_reminders', 2);
        $this->assertSame(1, (int) $session->fresh()->reminder_attempts);
        $this->assertCount(2, $this->sender->messages);
    }

    public function test_stale_queued_reminder_never_sends_after_reschedule(): void
    {
        $session = $this->appointment();
        Queue::fake();
        app(NotificationService::class)->sessionReminder($session, '24h');
        $row = NotificationOutbox::where('dedupe_key', hash('sha256', 'whatsapp:session.reminder:'.$session->id.':'.hash('sha256', SessionClock::fromStored($session->session_date, (string) $session->session_time)->toIso8601String()).':24h:'.$this->patientUser->id))->sole();
        $session->update(['session_date' => now()->addDays(3)->toDateString()]);
        app(NotificationOutboxService::class)->execute($row->id, $row->claim_token);
        $this->assertCount(0, $this->sender->messages);
        $this->assertSame('skipped', NotificationLog::where('channel', 'whatsapp')->sole()->status);
    }

    private function rejectAuditInserts(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION ops_reject_audit_insert() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'synthetic audit outage'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER ops_reject_audit_insert BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION ops_reject_audit_insert();");
        } else {
            DB::unprepared("CREATE TRIGGER ops_reject_audit_insert BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'synthetic audit outage'); END;");
        }
    }

    public function test_failed_sensitive_audit_rolls_back_session_transition(): void
    {
        $session = $this->appointment();
        $this->rejectAuditInserts();
        try {
            app(SessionService::class)->cancel($session, $this->therapistUser);
            $this->fail('Audit failure must propagate.');
        } catch (QueryException) {
        }
        $this->assertSame('confirmed', $session->fresh()->status->value);
        $this->assertDatabaseCount('session_status_logs', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('ops_notification_outbox', 0);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_successful_sensitive_transition_has_audit_and_preserves_append_only(): void
    {
        $session = $this->appointment();
        app(SessionService::class)->cancel($session, $this->therapistUser);
        $this->assertSame('cancelled', $session->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $session->id, 'action' => AuditLogService::SESSION_TRANSITIONED]);
        foreach ([fn () => AuditLog::query()->update(['action' => 'tampered']), fn () => DB::table('audit_logs')->delete()] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Append-only mutation must fail.');
            } catch (QueryException) {
            }
        }
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_postgresql_audit_insert_error_aborts_transaction_until_rollback(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL transaction abort semantics require PostgreSQL.');
        }
        $this->rejectAuditInserts();
        DB::beginTransaction();
        try {
            app(AuditLogService::class)->record($this->patientUser, 'ops.synthetic');
            $this->fail('Insert must fail.');
        } catch (QueryException) {
        }
        try {
            DB::select('SELECT 1');
            $this->fail('PostgreSQL must mark the transaction aborted.');
        } catch (QueryException $e) {
            $this->assertSame('25P02', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_readiness_checks_database_but_liveness_remains_up_and_responses_are_redacted(): void
    {
        $this->getJson('/ready')->assertOk()->assertExactJson(['status' => 'ready'])->assertHeader('Cache-Control', 'no-store, private');
        $original = config('database.default');
        config(['database.default' => 'ops_broken', 'database.connections.ops_broken' => array_merge(config('database.connections.pgsql'), ['host' => '127.0.0.1', 'port' => 1, 'password' => 'synthetic-sensitive-password'])]);
        $this->getJson('/up')->assertOk();
        $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        config(['database.default' => $original]);
        $this->getJson('/ready')->assertOk();
    }

    public function test_readiness_requires_working_cache(): void
    {
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('synthetic cache password'));
        $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }

    public function test_production_requires_explicit_safe_configuration(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        $this->app->detectEnvironment(fn () => 'testing');
    }

    public function test_real_worker_loop_heartbeat_and_scheduler_expire_with_controlled_clock(): void
    {
        config(['operations.require_heartbeats' => true]);
        $this->getJson('/ready')->assertStatus(503);
        event(new Looping('database', 'default'));
        collect(app(Schedule::class)->events())->firstWhere('description', 'ops-scheduler-heartbeat')->run($this->app);
        $this->getJson('/ready')->assertOk();
        $this->artisan('ops:health worker')->assertExitCode(0);
        $this->artisan('ops:health scheduler')->assertExitCode(0);
        $this->travel(181)->seconds();
        $this->getJson('/ready')->assertStatus(503);
        $this->artisan('ops:health worker')->assertExitCode(1);
        $this->artisan('ops:health scheduler')->assertExitCode(1);
    }

    public function test_monitor_signals_overdue_outbox_and_failed_jobs_without_payloads(): void
    {
        app(OperationsHealth::class)->heartbeat('worker');
        app(OperationsHealth::class)->heartbeat('scheduler');
        $this->artisan('ops:monitor')->assertExitCode(0);
        Queue::fake();
        app(NotificationDispatcher::class)->whatsApp($this->patientUser, 'Synthetic secret-message', 'ops.monitor:1');
        NotificationOutbox::query()->update(['created_at' => now()->subMinutes(6)]);
        $result = app(OperationsHealth::class)->diagnostics();
        $this->assertSame(1, $result['outbox_overdue']);
        $this->assertStringNotContainsString('secret-message', json_encode($result));
        $this->artisan('ops:monitor')->assertExitCode(1);
    }

    public function test_broadcast_is_private_contains_no_clinical_data_and_is_outboxed_independently(): void
    {
        $session = $this->appointment();
        Queue::fake();
        app(NotificationService::class)->sessionBooked($session);
        $id = DatabaseNotification::where('notifiable_id', $this->patientUser->id)->sole()->id;
        $event = new BroadcastNotificationCreated($this->patientUser, new StoredNotificationAvailable($id));
        $this->assertSame('private-App.Models.User.'.$this->patientUser->id, $event->broadcastOn()[0]->name);
        $this->assertSame(['id' => $id, 'kind' => 'notification_available'], $event->broadcastWith());

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')->once()->withArgs(fn ($channels, $name, $payload) => $channels[0]->name === 'private-App.Models.User.'.$this->patientUser->id && $payload === ['id' => $id, 'kind' => 'notification_available']);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->once()->andReturn($broadcaster);
        (new BroadcastStoredNotificationJob($this->patientUser->id, $id))->handle($factory);
        $this->patientUser->update(['is_active' => false]);
        (new BroadcastStoredNotificationJob($this->patientUser->id, $id))->handle($factory);
    }

    public function test_s3_adapter_dependency_is_present_without_external_storage_calls(): void
    {
        $this->assertTrue(class_exists(AwsS3V3Adapter::class));
        $this->assertTrue(version_compare(InstalledVersions::getPrettyVersion('league/commonmark'), '2.10.3', '>='));
    }

    public function test_private_notification_channel_authorizes_only_active_verified_owner(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'synthetic-key',
            'broadcasting.connections.reverb.secret' => 'synthetic-secret', 'broadcasting.connections.reverb.app_id' => 'ops-test']);
        require base_path('routes/channels.php');
        $payload = ['channel_name' => 'private-App.Models.User.'.$this->patientUser->id, 'socket_id' => '1234.5678'];
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertUnauthorized();
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
        Sanctum::actingAs($this->therapistUser, ['*'], 'api');
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertForbidden();
        $this->patientUser->update(['is_active' => false]);
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertForbidden();
        $this->patientUser->update(['is_active' => true, 'phone_verified_at' => null]);
        Sanctum::actingAs($this->patientUser, ['*'], 'api');
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertForbidden();
    }

    public function test_broadcast_never_publishes_foreign_or_anonymized_notification(): void
    {
        Queue::fake();
        $session = $this->appointment();
        app(NotificationService::class)->sessionReminder($session, '24h');
        $id = DatabaseNotification::where('notifiable_id', $this->patientUser->id)->sole()->id;
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');
        (new BroadcastStoredNotificationJob($this->therapistUser->id, $id))->handle($factory);
        $this->patientUser->forceFill(['anonymized_at' => now()])->save();
        (new BroadcastStoredNotificationJob($this->patientUser->id, $id))->handle($factory);
    }
}
