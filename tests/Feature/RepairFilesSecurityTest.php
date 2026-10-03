<?php

namespace Tests\Feature;

use App\Events\Chat\MessageSent;
use App\Models\DocumentRequest;
use App\Models\Patient;
use App\Models\Support;
use App\Models\Therapist;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Chat\ChatService;
use App\Services\Documents\DocumentRequestService;
use App\Services\NotificationService;
use App\Services\Support\SupportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Tests\Concerns\CommittedDatabase;
use Tests\TestCase;

class RepairFilesSecurityTest extends TestCase
{
    use CommittedDatabase;

    private User $patient;

    private User $therapist;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 15)->setTime(12, 0));
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'sakina.uploads_disk' => 'local']);
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('deliver')->zeroOrMoreTimes();
        $this->app->instance(NotificationService::class, $notifications);
        $this->patient = $this->user('patient');
        $this->therapist = $this->user('therapist');
        $this->admin = $this->user('admin');
        Therapist::create(['user_id' => $this->therapist->id, 'full_name' => 'Synthetic therapist',
            'specialty' => 'cbt', 'country' => 'JO', 'availability' => [], 'approval_status' => 'approved']);
        Patient::create(['user_id' => $this->patient->id, 'full_name' => 'Synthetic patient',
            'age' => 25, 'gender' => 'other', 'language' => 'en', 'therapist_id' => $this->therapist->id]);
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => 'Synthetic '.$role, 'email' => Str::uuid().'@example.invalid',
            'password' => 'synthetic-only', 'role' => $role, 'whatsapp_number' => '+9639'.random_int(10000000, 99999999),
            'is_active' => true, 'phone_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, ['*'], 'api');

        return $this;
    }

    private function document(string $status = 'requested', ?string $path = null): DocumentRequest
    {
        return DocumentRequest::create(['user_id' => $this->therapist->id, 'requested_by' => $this->admin->id,
            'doc_type' => 'license', 'status' => $status, 'file_path' => $path, 'timestamp' => now()]);
    }

    public function test_false_and_empty_writes_never_report_success_or_replace_previous_document(): void
    {
        $previous = "uploads/{$this->therapist->id}/document_requests/old.png";
        Storage::disk('local')->put($previous, 'synthetic previous file');
        $real = Storage::disk('local');
        $doc = $this->document('rejected', $previous);

        foreach ([false, ''] as $result) {
            $disk = Mockery::mock(FilesystemAdapter::class);
            $disk->shouldReceive('putFileAs')->andReturn($result);
            Storage::set('local', $disk);
            $this->as($this->therapist)->post('/api/v1/files/upload', [
                'purpose' => 'other', 'file' => UploadedFile::fake()->image('generic.png'),
            ], ['Accept' => 'application/json'])->assertStatus(503);
            $this->post("/api/v1/therapists/me/documents/{$doc->id}/upload", [
                'file' => UploadedFile::fake()->image('replacement.png'),
            ], ['Accept' => 'application/json'])->assertStatus(503);
            $this->as($this->patient)->post('/api/v1/patients/support', [
                'type' => 'technical', 'description' => 'Synthetic issue', 'attachment' => UploadedFile::fake()->image('issue.png'),
            ], ['Accept' => 'application/json'])->assertStatus(503);
            $this->assertSame('rejected', $doc->fresh()->status);
            $this->assertSame($previous, $doc->fresh()->file_path);
            $this->assertTrue($real->exists($previous));
            $this->assertDatabaseCount('supports', 0);
        }
    }

    public function test_throwing_storage_returns_controlled_unavailable_without_document_transition(): void
    {
        $doc = $this->document();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->andThrow(UnableToWriteFile::atLocation('synthetic'));
        Storage::set('local', $disk);
        $this->as($this->therapist)->post("/api/v1/therapists/me/documents/{$doc->id}/upload", [
            'file' => UploadedFile::fake()->image('scan.png'),
        ], ['Accept' => 'application/json'])->assertStatus(503)->assertHeader('Retry-After', '5');
        $this->assertSame('requested', $doc->fresh()->status);
        $this->assertNull($doc->fresh()->file_path);
    }

    public function test_false_empty_and_throwing_chat_writes_never_create_message_metadata(): void
    {
        foreach ([false, '', UnableToWriteFile::atLocation('synthetic')] as $result) {
            $disk = Mockery::mock(FilesystemAdapter::class);
            if ($result instanceof \Throwable) {
                $disk->shouldReceive('putFileAs')->once()->andThrow($result);
            } else {
                $disk->shouldReceive('putFileAs')->once()->andReturn($result);
            }
            Storage::set('local', $disk);
            $this->as($this->patient)->post('/api/v1/chat/'.$this->therapist->id, [
                'attachment' => UploadedFile::fake()->image('chat.png'),
            ], ['Accept' => 'application/json'])->assertStatus($result instanceof \Throwable ? 503 : 422);
            $this->assertDatabaseCount('messages', 0);
        }
    }

    public function test_document_failure_rolls_back_row_and_discards_new_file_not_previous_file(): void
    {
        $previous = "uploads/{$this->therapist->id}/document_requests/old.png";
        Storage::disk('local')->put($previous, 'synthetic previous file');
        $doc = $this->document('rejected', $previous);
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('synthetic audit failure'));
        $this->app->instance(AuditLogService::class, $audit);

        try {
            app(DocumentRequestService::class)->upload($this->therapist, $doc->id, UploadedFile::fake()->image('new.png'));
            $this->fail('The synthetic transaction failure was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic audit failure', $e->getMessage());
        }

        $this->assertSame($previous, $doc->fresh()->file_path);
        $this->assertSame('rejected', $doc->fresh()->status);
        $this->assertSame([$previous], Storage::disk('local')->allFiles());
    }

    public function test_replacement_deletes_previous_file_only_after_outer_commit(): void
    {
        $previous = "uploads/{$this->therapist->id}/document_requests/old.png";
        Storage::disk('local')->put($previous, 'synthetic previous file');
        $doc = $this->document('rejected', $previous);
        DB::beginTransaction();
        $updated = app(DocumentRequestService::class)->upload($this->therapist, $doc->id, UploadedFile::fake()->image('new.png'));
        Storage::disk('local')->assertExists($previous);
        Storage::disk('local')->assertExists($updated->file_path);
        DB::commit();
        Storage::disk('local')->assertMissing($previous);
        $this->assertSame('submitted', $updated->status);
    }

    public function test_false_cleanup_does_not_fail_committed_replacement_and_old_path_is_inaccessible(): void
    {
        $previous = "uploads/{$this->therapist->id}/document_requests/old.png";
        Storage::disk('local')->put($previous, 'synthetic previous file');
        $doc = $this->document('rejected', $previous);
        $real = Storage::disk('local');
        $disk = Mockery::mock($real);
        $disk->shouldReceive('delete')->with($previous)->once()->andReturn(false);
        Storage::set('local', $disk);
        Log::spy();
        $this->as($this->therapist)->post("/api/v1/therapists/me/documents/{$doc->id}/upload", [
            'file' => UploadedFile::fake()->image('new.png'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertNotSame($previous, $doc->fresh()->file_path);
        $this->assertTrue($real->exists($previous));
        $this->assertTrue($real->exists($doc->fresh()->file_path));
        $this->get('/api/v1/files/download/'.$previous)->assertNotFound();
        $this->as($this->admin)->get('/api/v1/files/download/'.$previous)->assertNotFound();
        Log::shouldHaveReceived('warning')->with('Private file cleanup failed.', ['disk' => 'local', 'path_hash' => hash('sha256', $previous)])->once();
    }

    public function test_throwing_cleanup_never_discards_a_committed_replacement_or_masks_a_rollback(): void
    {
        $previous = "uploads/{$this->therapist->id}/document_requests/old.png";
        Storage::disk('local')->put($previous, 'synthetic previous file');
        $doc = $this->document('rejected', $previous);
        $real = Storage::disk('local');
        $disk = Mockery::mock($real);
        $disk->shouldReceive('delete')->with($previous)->once()->andThrow(new \RuntimeException('synthetic delete failure'));
        Storage::set('local', $disk);
        $updated = app(DocumentRequestService::class)->upload($this->therapist, $doc->id, UploadedFile::fake()->image('new.png'));
        $this->assertSame('submitted', $doc->fresh()->status);
        $this->assertTrue($real->exists($updated->file_path));
        $this->assertTrue($real->exists($previous));

        $pending = $this->document();
        $disk->shouldReceive('delete')->once()->andThrow(UnableToDeleteFile::atLocation('synthetic'));
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('synthetic original failure'));
        $this->app->instance(AuditLogService::class, $audit);
        try {
            app(DocumentRequestService::class)->upload($this->therapist, $pending->id, UploadedFile::fake()->image('rollback.png'));
            $this->fail('The transaction failure was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic original failure', $e->getMessage());
        }
        $this->assertSame('requested', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->file_path);
    }

    public function test_missing_document_requires_rejection_note_then_allows_reupload_without_old_locator(): void
    {
        $doc = $this->document();
        $this->as($this->therapist)->post("/api/v1/therapists/me/documents/{$doc->id}/upload", [
            'file' => UploadedFile::fake()->image('first.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        $old = $doc->fresh()->file_path;
        Storage::disk('local')->delete($old);
        $this->getJson("/api/v1/therapists/me/documents/{$doc->id}")
            ->assertOk()->assertJsonPath('data.has_file', false)->assertJsonPath('data.download_url', null);
        $this->as($this->admin)->postJson("/api/v1/admin/documents/{$doc->id}/review", ['action' => 'approve'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/documents/{$doc->id}/review", ['action' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/admin/documents/{$doc->id}/review", ['action' => 'reject', 'note' => 'Missing object; please resubmit.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.accepts_upload', true)
            ->assertJsonPath('data.download_url', null)->assertJsonPath('data.original_name', null);
        $this->assertNull($doc->fresh()->file_path);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $doc->id, 'action' => 'document.rejected']);
        $this->as($this->therapist)->post("/api/v1/therapists/me/documents/{$doc->id}/upload", [
            'file' => UploadedFile::fake()->image('second.png'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertNotSame($old, $doc->fresh()->file_path);
        Storage::disk('local')->assertExists($doc->fresh()->file_path);
        $this->as($this->admin)->postJson("/api/v1/admin/documents/{$doc->id}/review", ['action' => 'approve'])->assertOk();
        $this->as($this->therapist)->post("/api/v1/therapists/me/documents/{$doc->id}/upload", [
            'file' => UploadedFile::fake()->image('third.png'),
        ], ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_support_locator_is_assignment_scoped_rechecked_and_audited(): void
    {
        $created = $this->as($this->patient)->post('/api/v1/patients/support', [
            'type' => 'technical', 'description' => 'Synthetic issue', 'attachment' => UploadedFile::fake()->image('issue.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $ticket = Support::findOrFail($created->json('data.id'));
        $locator = "/api/v1/files/download/support/{$ticket->id}/attachment";
        $agent = $this->user('support_agent');
        $other = $this->user('support_agent');
        $ticket->update(['assigned_to' => $agent->id]);
        $view = $this->as($agent)->getJson("/api/v1/admin/support/{$ticket->id}")->assertOk();
        $view->assertJsonPath('data.download_url', url($locator));
        $this->assertArrayNotHasKey('file_path', $view->json('data'));
        $download = $this->get($locator)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $agent->id, 'entity_id' => $ticket->id, 'action' => 'file.downloaded']);
        $this->get('/api/v1/files/download/'.$ticket->file_path)->assertNotFound();
        $this->as($other)->getJson("/api/v1/admin/support/{$ticket->id}")->assertOk()->assertJsonPath('data.download_url', null);
        $this->get($locator)->assertNotFound();
        $this->as($this->user('patient'))->get($locator)->assertNotFound();
        $this->as($this->patient)->get($locator)->assertOk();
        $this->as($this->admin)->get($locator)->assertOk();
        $ticket->update(['assigned_to' => $other->id]);
        $this->as($agent)->get($locator)->assertNotFound();
        $this->as($other)->post($locator)->assertOk();
        Storage::disk('local')->delete($ticket->file_path);
        $this->get($locator)->assertNotFound();
        $this->get('/api/v1/files/download/support/'.Str::uuid().'/attachment')->assertNotFound();
    }

    public function test_support_locator_cannot_be_used_to_serve_chat_or_traverse_paths(): void
    {
        $conversation = app(ChatService::class)->open($this->patient, $this->therapist->id);
        $path = "chat/{$conversation->id}/sensitive.png";
        Storage::disk('local')->put($path, 'synthetic private chat');
        $agent = $this->user('support_agent');
        $ticket = Support::create(['id' => (string) Str::uuid(), 'user_id' => $this->patient->id, 'type' => 'technical',
            'description' => 'Synthetic invalid reference', 'file_path' => $path, 'assigned_to' => $agent->id]);
        $this->as($agent)->get("/api/v1/files/download/support/{$ticket->id}/attachment")->assertNotFound();
        $this->get('/api/v1/files/download/'.$path)->assertNotFound();
        foreach (["uploads/{$this->patient->id}/../other/file", "/uploads/{$this->patient->id}/file", "uploads/{$this->patient->id}/x%00"] as $bad) {
            $this->post('/api/v1/files/download/'.$bad, [], ['Accept' => 'application/json'])->assertForbidden();
        }
    }

    public function test_private_chat_downloads_are_no_store_for_both_locators_and_participants_only(): void
    {
        $message = app(ChatService::class)->send($this->patient, $this->therapist->id, null, UploadedFile::fake()->image('private.png'));
        $attachment = "/api/v1/chat/attachments/{$message->id}";
        $generic = '/api/v1/files/download/'.$message->file_path;
        foreach ([$this->patient, $this->therapist] as $participant) {
            foreach ([$attachment, $generic] as $url) {
                $download = $this->as($participant)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
                $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
                $this->assertStringContainsString('private', $download->headers->get('Cache-Control'));
            }
        }
        $this->as($this->admin)->get($generic)->assertNotFound();
        $this->as($this->user('patient'))->get($attachment)->assertNotFound();
    }

    public function test_support_transaction_failure_cleans_uploaded_bytes(): void
    {
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('synthetic support failure'));
        $this->app->instance(AuditLogService::class, $audit);
        try {
            app(SupportService::class)->create($this->patient, ['type' => 'technical', 'description' => 'Synthetic issue'], UploadedFile::fake()->image('issue.png'));
            $this->fail('The synthetic transaction failure was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic support failure', $e->getMessage());
        }
        $this->assertDatabaseCount('supports', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_log_broadcaster_never_receives_plaintext_even_if_queue_configuration_changes(): void
    {
        $entries = [];
        Log::listen(function ($entry) use (&$entries) {
            $entries[] = $entry->message;
        });
        config(['broadcasting.default' => 'log', 'queue.default' => 'sync']);
        $message = app(ChatService::class)->send($this->patient, $this->therapist->id, 'SYNTHETIC_CLINICAL_SECRET', null);
        $this->assertStringNotContainsString('SYNTHETIC_CLINICAL_SECRET', DB::table('messages')->where('id', $message->id)->value('content'));
        $this->assertStringNotContainsString('SYNTHETIC_CLINICAL_SECRET', implode("\n", $entries));
        $this->assertContains('Sensitive chat broadcasting is disabled for log connections.', $entries);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');
        config(['broadcasting.default' => 'aliased-log', 'broadcasting.connections.aliased-log.driver' => 'log']);
        (new BroadcastEvent(new MessageSent($message)))->handle($factory);
        $this->assertStringNotContainsString('SYNTHETIC_CLINICAL_SECRET', implode("\n", $entries));
        config(['broadcasting.default' => 'reverb']);
        $event = new MessageSent($message);
        $this->assertSame('chat.message.sent', $event->broadcastAs());
        $this->assertSame(['private-chat.conversation.'.$message->conversation_id, 'private-App.Models.User.'.$message->receiver_id], array_map(fn ($channel) => (string) $channel, $event->broadcastOn()));
        $this->assertSame('SYNTHETIC_CLINICAL_SECRET', $event->broadcastWith()['message']['content']);
    }
}
