<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotificationOutboxJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\DocumentRequest;
use App\Models\NotificationOutbox;
use App\Models\Package;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Notifications\StoredNotificationAvailable;
use App\Services\AuditLogService;
use App\Services\Billing\PaymentReviewService;
use App\Services\Chat\ChatService;
use App\Services\Documents\DocumentRequestService;
use App\Services\Files\SecureFileService;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationOutboxService;
use App\Services\Patient\AccountAnonymizer;
use App\Services\Subscription\SubscriptionService;
use App\Services\Support\SupportService;
use App\Services\Therapist\TherapistService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

#[Group('integration-added')]
class IntegrationBoundaryRegressionTest extends TestCase
{
    use CommittedDatabase;

    private User $user;

    private User $clinician;

    private User $admin;

    private Patient $patient;

    private Package $package;

    private TherapySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2030, 1, 15)->setTime(12, 0));
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'sakina.uploads_disk' => 'local']);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->user = $this->makeUser('patient');
        $this->clinician = $this->makeUser('therapist');
        $this->admin = $this->makeUser('admin');
        Therapist::create(['user_id' => $this->clinician->id, 'full_name' => 'Synthetic therapist',
            'specialty' => 'cbt', 'country' => 'JO', 'availability' => [], 'approval_status' => 'approved']);
        $this->patient = Patient::create(['user_id' => $this->user->id, 'full_name' => 'Synthetic patient',
            'age' => 25, 'gender' => 'other', 'language' => 'en', 'therapist_id' => $this->clinician->id]);
        $this->package = Package::create(['code' => 'synthetic', 'name' => 'Synthetic package', 'price' => 150,
            'number_of_sessions' => 4, 'duration_days' => 28, 'daily_sessions_quota' => 1, 'is_published' => true]);
        $this->session = TherapySession::create(['patient_id' => $this->user->id, 'therapist_id' => $this->clinician->id,
            'session_date' => now()->addDay()->toDateString(), 'session_time' => '10:00',
            'medium' => 'meet', 'status' => 'confirmed', 'payment_status' => 'pending', 'price' => 50, 'is_initial' => false]);
    }

    private function makeUser(string $role): User
    {
        $user = User::create(['name' => 'Synthetic '.$role, 'email' => Str::uuid().'@example.invalid',
            'password' => 'synthetic-only', 'role' => $role, 'whatsapp_number' => '+9639'.random_int(10000000, 99999999),
            'is_active' => true, 'phone_verified_at' => now(), 'timezone' => 'UTC']);
        $user->assignRole($role);

        return $user;
    }

    private function submit(string $kind): string
    {
        $proof = UploadedFile::fake()->create('synthetic-proof.pdf', 1, 'application/pdf');

        return $kind === 'subscription'
            ? app(SubscriptionService::class)->createWithProof($this->patient, $this->package->id, $proof)['payment']->proof_file_path
            : app(PaymentReviewService::class)->submitSessionProof($this->session, $proof)->proof_file_path;
    }

    public static function proofKinds(): array
    {
        return [['subscription'], ['session']];
    }

    #[DataProvider('proofKinds')]
    public function test_outer_rollback_deletes_child_committed_proof_and_delivery_intent(string $kind): void
    {
        DB::beginTransaction();
        DB::beginTransaction();
        $path = $this->submit($kind);
        DB::commit(); // committed child callbacks are not sufficient for the parent rollback
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseCount('ops_notification_outbox', 1);
        DB::rollBack();
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('ops_notification_outbox', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('proofKinds')]
    public function test_confirmed_domain_rollback_cleans_proof_without_masking_audit_error(string $kind): void
    {
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new \RuntimeException('Synthetic audit outage.')));
        try {
            $this->submit($kind);
            $this->fail('Audit failure must roll back the domain write.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit outage.', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('payment-proofs'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('ops_notification_outbox', 0);
    }

    #[DataProvider('proofKinds')]
    public function test_after_commit_exception_preserves_referenced_proof_and_durable_outbox(string $kind): void
    {
        Event::listen('eloquent.created: '.Payment::class, fn () => DB::afterCommit(fn () => throw new \RuntimeException('Synthetic after-commit exception.')));
        try {
            $this->submit($kind);
            $this->fail('The adversarial callback must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic after-commit exception.', $e->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $path = Payment::sole()->proof_file_path;
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ops_notification_outbox', 1);
        app(NotificationOutboxService::class)->replay();
        $this->assertSame('queued', NotificationOutbox::sole()->status);
    }

    public static function failedProofWrites(): array
    {
        return [['subscription', false], ['subscription', ''], ['session', false], ['session', '']];
    }

    #[DataProvider('failedProofWrites')]
    public function test_failed_proof_write_never_creates_domain_rows(string $kind, mixed $result): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn($result);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            $this->submit($kind);
            $this->fail('A missing proof must not be accepted.');
        } catch (ServiceUnavailableHttpException) {
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('subscriptions', 0);
        }
    }

    public function test_document_replacement_and_outbox_roll_back_without_deleting_previous_file(): void
    {
        $previous = "uploads/{$this->clinician->id}/document_requests/old.pdf";
        Storage::disk('local')->put($previous, 'Synthetic old document');
        $doc = DocumentRequest::create(['user_id' => $this->clinician->id, 'requested_by' => $this->admin->id,
            'doc_type' => 'license', 'status' => 'rejected', 'file_path' => $previous, 'timestamp' => now()]);
        DB::beginTransaction();
        $updated = app(DocumentRequestService::class)->upload($this->clinician, $doc->id, UploadedFile::fake()->create('new.pdf', 1, 'application/pdf'));
        $path = $updated->file_path;
        $this->assertDatabaseCount('ops_notification_outbox', 1);
        DB::rollBack();
        $this->assertSame($previous, $doc->fresh()->file_path);
        Storage::disk('local')->assertExists($previous);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('ops_notification_outbox', 0);
    }

    public function test_document_commit_exception_does_not_delete_new_committed_file(): void
    {
        $doc = DocumentRequest::create(['user_id' => $this->clinician->id, 'requested_by' => $this->admin->id,
            'doc_type' => 'license', 'status' => 'requested', 'timestamp' => now()]);
        Event::listen('eloquent.updated: '.DocumentRequest::class, fn () => DB::afterCommit(fn () => throw new \RuntimeException('Synthetic committed document.')));
        try {
            app(DocumentRequestService::class)->upload($this->clinician, $doc->id, UploadedFile::fake()->create('doc.pdf', 1, 'application/pdf'));
            $this->fail('Expected callback failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic committed document.', $e->getMessage());
        }
        Storage::disk('local')->assertExists($doc->fresh()->file_path);
        $this->assertDatabaseCount('ops_notification_outbox', 1);
    }

    public function test_chat_attachment_and_delivery_intent_follow_outer_rollback(): void
    {
        DB::beginTransaction();
        $message = app(ChatService::class)->send($this->user, $this->clinician->id, 'Synthetic chat', UploadedFile::fake()->image('safe.png'));
        $this->assertDatabaseCount('ops_notification_outbox', 1);
        DB::rollBack();
        Storage::disk('local')->assertMissing($message->file_path);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('ops_notification_outbox', 0);
    }

    public function test_support_upload_following_outer_rollback_leaves_no_bytes(): void
    {
        DB::beginTransaction();
        $ticket = app(SupportService::class)->create($this->user, ['type' => 'technical', 'subject' => 'Synthetic', 'description' => 'Synthetic support'], UploadedFile::fake()->create('support.pdf', 1, 'application/pdf'));
        DB::rollBack();
        Storage::disk('local')->assertMissing($ticket->file_path);
        $this->assertDatabaseCount('supports', 0);
    }

    public function test_completed_erasure_rejects_stale_proof_generic_and_chat_writers(): void
    {
        $oldUser = clone $this->user;
        app(AccountAnonymizer::class)->anonymize($this->user);
        foreach ([
            fn () => $this->submit('subscription'),
            fn () => $this->submit('session'),
            fn () => app(SecureFileService::class)->upload($oldUser, UploadedFile::fake()->create('stale.pdf', 1), 'other'),
            fn () => app(SupportService::class)->create($oldUser, ['type' => 'technical', 'subject' => 'Synthetic', 'description' => 'Stale write'], UploadedFile::fake()->create('stale.pdf', 1)),
        ] as $write) {
            try {
                $write();
                $this->fail('A stale writer must be fenced after committed erasure.');
            } catch (AccessDeniedHttpException) {
                $this->assertSame([], Storage::disk('local')->allFiles());
            }
        }
        try {
            app(ChatService::class)->send($oldUser, $this->clinician->id, 'Synthetic stale chat', null);
            $this->fail('Erased care relationship must not reopen chat.');
        } catch (NotFoundHttpException) {
            $this->assertDatabaseCount('messages', 0);
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_download_constructed_before_erasure_emits_no_bytes_after_commit(): void
    {
        $path = "uploads/{$this->user->id}/other/synthetic.pdf";
        Storage::disk('local')->put($path, 'Synthetic sensitive bytes');
        $response = app(SecureFileService::class)->download($this->admin, $path);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        app(AccountAnonymizer::class)->anonymize($this->user);
        ob_start();
        try {
            $response->sendContent();
            $this->fail('The stale response must not emit erased bytes.');
        } catch (AccessDeniedHttpException) {
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function test_erasure_does_not_allow_cached_notification_user_to_recreate_ledgers(): void
    {
        $stale = clone $this->user;
        app(AccountAnonymizer::class)->anonymize($this->user);
        $dispatcher = app(NotificationDispatcher::class);
        $notice = new StoredNotificationAvailable((string) Str::uuid());
        $this->assertFalse($dispatcher->inApp($stale, $notice, 'synthetic:stale'));
        $dispatcher->whatsApp($stale, 'Synthetic erased recipient', 'synthetic:stale');
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseCount('ops_notification_outbox', 0);
    }

    public static function downloadKinds(): array
    {
        return [['generic'], ['chat'], ['support']];
    }

    #[DataProvider('downloadKinds')]
    public function test_authorized_streams_emit_bytes_and_each_stale_locator_is_fenced_after_erasure(string $kind): void
    {
        if ($kind === 'chat') {
            $message = app(ChatService::class)->send($this->user, $this->clinician->id, 'Synthetic chat', UploadedFile::fake()->image('stream.png'));
            $path = $locator = $message->file_path;
        } elseif ($kind === 'support') {
            $ticket = app(SupportService::class)->create($this->user, ['type' => 'technical', 'subject' => 'Synthetic', 'description' => 'Synthetic support'], UploadedFile::fake()->create('stream.pdf', 1));
            $path = $ticket->file_path;
            $locator = "support/{$ticket->id}/attachment";
        } else {
            $path = "uploads/{$this->user->id}/other/stream.pdf";
            $locator = rawurlencode($path); // Normalize before the stream-time family lookup too.
        }
        Storage::disk('local')->put($path, 'Synthetic streamed bytes');
        $current = app(SecureFileService::class)->download($this->user, $locator);
        $stale = app(SecureFileService::class)->download($this->user, $locator);
        $this->assertStringContainsString('no-store', $current->headers->get('Cache-Control'));
        ob_start();
        try {
            $current->sendContent();
            $this->assertSame('Synthetic streamed bytes', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        app(AccountAnonymizer::class)->anonymize($this->user);
        ob_start();
        try {
            $stale->sendContent();
            $this->fail('Erasure must fence every stale locator before emitting bytes.');
        } catch (AccessDeniedHttpException|NotFoundHttpException) {
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function test_claimed_whatsapp_job_cannot_deliver_after_committed_recipient_erasure(): void
    {
        app(NotificationDispatcher::class)->whatsApp($this->user, 'Synthetic pending message', 'synthetic:claimed');
        Queue::assertPushed(DeliverNotificationOutboxJob::class);
        // Simulate a worker which has already deserialized the protected payload.
        $pending = unserialize(Crypt::decryptString(NotificationOutbox::sole()->payload));
        $this->assertInstanceOf(SendWhatsAppMessageJob::class, $pending);
        $outbox = NotificationOutbox::sole();
        $this->assertDatabaseCount('notification_logs', 1);
        app(AccountAnonymizer::class)->anonymize($this->user);
        $this->assertNull($outbox->fresh()->payload);
        $this->assertSame('recipient_erased', $outbox->fresh()->last_error);
        $this->assertNotNull($outbox->fresh()->completed_at);
        $sender = Mockery::mock(WhatsAppSenderInterface::class);
        $sender->shouldNotReceive('send');
        $pending->handle($sender);
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_erasure_removes_pending_operational_bytes_without_discarding_unrelated_delivery(): void
    {
        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->whatsApp($this->user, 'Synthetic patient operational message', 'synthetic:patient');
        $patientRow = NotificationOutbox::sole();
        $dispatcher->whatsApp($this->admin, 'Synthetic unrelated admin message', 'synthetic:admin');
        $adminRow = NotificationOutbox::where('id', '!=', $patientRow->id)->sole();
        $adminPayload = $adminRow->payload;
        app(AccountAnonymizer::class)->anonymize($this->user);
        $this->assertNull($patientRow->fresh()->payload);
        $this->assertSame('completed', $patientRow->fresh()->status);
        $this->assertSame($adminPayload, $adminRow->fresh()->payload);
        $this->assertNull($adminRow->fresh()->completed_at);
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->admin->id]);
    }

    public function test_license_replacement_retains_old_bytes_until_commit_and_cleans_only_rolled_back_bytes(): void
    {
        $therapist = $this->clinician->therapist;
        $previous = "licenses/{$therapist->user_id}/old.pdf";
        Storage::disk('local')->put($previous, 'Synthetic old license');
        $therapist->update(['approval_status' => 'rejected', 'license_file_path' => $previous]);
        DB::beginTransaction();
        $updated = app(TherapistService::class)->submitForApproval($therapist, UploadedFile::fake()->create('new.pdf', 1, 'application/pdf'));
        $path = $updated->license_file_path;
        Storage::disk('local')->assertExists($previous);
        Storage::disk('local')->assertExists($path);
        DB::rollBack();
        Storage::disk('local')->assertExists($previous);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame($previous, $therapist->fresh()->license_file_path);
        $updated = app(TherapistService::class)->submitForApproval($therapist->fresh(), UploadedFile::fake()->create('committed.pdf', 1, 'application/pdf'));
        Storage::disk('local')->assertMissing($previous);
        Storage::disk('local')->assertExists($updated->license_file_path);
        $this->assertSame('pending', $updated->approval_status->value);
    }

    public function test_license_after_commit_exception_preserves_committed_replacement(): void
    {
        $therapist = $this->clinician->therapist;
        $therapist->update(['approval_status' => 'rejected']);
        Event::listen('eloquent.updated: '.Therapist::class, fn () => DB::afterCommit(fn () => throw new \RuntimeException('Synthetic license callback.')));
        try {
            app(TherapistService::class)->submitForApproval($therapist, UploadedFile::fake()->create('license.pdf', 1, 'application/pdf'));
            $this->fail('Expected committed callback exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic license callback.', $e->getMessage());
        }
        Storage::disk('local')->assertExists($therapist->fresh()->license_file_path);
        $this->assertSame('pending', $therapist->fresh()->approval_status->value);
    }

    public function test_clinical_retry_is_registered_with_real_scheduler(): void
    {
        $entries = collect(app(Schedule::class)->events());
        $event = $entries->first(fn ($event) => str_contains($event->command ?? '', 'clinical:deliver-notifications'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
