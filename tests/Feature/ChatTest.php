<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Events\Chat\MessageSent;
use App\Events\Chat\MessagesRead;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Therapist;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\Services\Chat\ChatService;
use App\Services\Patient\AccountAnonymizer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private User $patient;

    private User $therapist;

    private User $otherPatient;

    private User $otherTherapist;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->therapist = $this->makeUser('therapist', '+963900000201');
        $this->makeTherapist($this->therapist);

        $this->otherTherapist = $this->makeUser('therapist', '+963900000202');
        $this->makeTherapist($this->otherTherapist);

        $this->patient = $this->makeUser('patient', '+963900000203');
        $this->makePatient($this->patient, $this->therapist);

        $this->otherPatient = $this->makeUser('patient', '+963900000204');
        $this->makePatient($this->otherPatient, $this->otherTherapist);

        $this->admin = $this->makeUser('admin', '+963900000205');
    }

    private function makeUser(string $role, string $whatsapp): User
    {
        $user = User::create([
            'name' => "Chat {$role} {$whatsapp}",
            'email' => "{$role}.{$whatsapp}@example.com",
            'password' => Hash::make('Secret123!'),
            'whatsapp_number' => $whatsapp,
            'role' => $role,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeTherapist(User $user, string $status = 'approved'): Therapist
    {
        return Therapist::create([
            'user_id' => $user->id,
            'full_name' => 'Dr. '.$user->whatsapp_number, 'specialty' => 'anxiety', 'country' => 'DE',
            'languages' => ['en'],
            'availability' => array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['09:00-12:00']
            ),
            'approval_status' => $status,
            'clients_limit' => 10,
        ]);
    }

    private function makePatient(User $user, ?User $therapist): Patient
    {
        return Patient::create([
            'user_id' => $user->id,
            'full_name' => 'Patient '.$user->whatsapp_number, 'age' => 30,
            'gender' => 'other', 'language' => 'en',
            'therapist_id' => $therapist?->id,
        ]);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user, ['*'], 'api');

        return $this;
    }

    private function send(User $from, User $to, array $payload)
    {
        return $this->as($from)->post("/api/v1/chat/{$to->id}", $payload, ['Accept' => 'application/json']);
    }

    private function disguised(string $body, string $name, string $claimedMime): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($tmp, $body);

        return new UploadedFile($tmp, $name, $claimedMime, null, true);
    }

    // ------------------------------------------------------------ authorization

    public function test_patient_and_assigned_therapist_can_exchange_text(): void
    {
        Event::fake([MessageSent::class]);

        $this->send($this->patient, $this->therapist, ['content' => '  Hello doctor  '])
            ->assertCreated()
            ->assertJsonPath('data.content', 'Hello doctor')
            ->assertJsonPath('data.attachment', null)
            ->assertJsonPath('data.is_read', false);

        $this->send($this->therapist, $this->patient, ['content' => 'Hello, how are you?'])->assertCreated();

        $conversation = Conversation::where('patient_id', $this->patient->id)->where('therapist_id', $this->therapist->id)->firstOrFail();
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertSame(1, Conversation::count());

        $this->as($this->patient)->getJson("/api/v1/chat/{$this->therapist->id}")
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id)
            ->assertJsonPath('conversation.unread_count', 1)
            ->assertJsonPath('conversation.counterpart_id', $this->therapist->id)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.content', 'Hello, how are you?');

        $this->as($this->therapist)->getJson('/api/v1/chat')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.counterpart_id', $this->patient->id);

        Event::assertDispatched(MessageSent::class, 2);

        $this->assertDatabaseHas('audit_logs', ['action' => 'chat.conversation_opened', 'entity_id' => $conversation->id]);
    }

    public function test_message_bodies_are_encrypted_at_rest(): void
    {
        $this->send($this->patient, $this->therapist, ['content' => 'very private words'])->assertCreated();

        $raw = DB::table('messages')->value('content');

        $this->assertNotSame('very private words', $raw);
        $this->assertStringNotContainsString('private', $raw);
        $this->assertSame('very private words', Message::first()->content);
    }

    public function test_unrelated_users_get_404_and_cannot_open_a_thread(): void
    {
        // Patient -> therapist who is not theirs.
        $this->send($this->patient, $this->otherTherapist, ['content' => 'hi'])->assertNotFound();
        // Therapist -> someone else's patient.
        $this->send($this->therapist, $this->otherPatient, ['content' => 'hi'])->assertNotFound();
        // Patient -> patient, therapist -> therapist, self.
        $this->send($this->patient, $this->otherPatient, ['content' => 'hi'])->assertNotFound();
        $this->send($this->therapist, $this->otherTherapist, ['content' => 'hi'])->assertNotFound();
        $this->send($this->patient, $this->patient, ['content' => 'hi'])->assertNotFound();
        // Non-existent counterpart looks identical to a forbidden one.
        $this->as($this->patient)->getJson('/api/v1/chat/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->as($this->patient)->getJson("/api/v1/chat/{$this->otherTherapist->id}")->assertNotFound();

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
    }

    public function test_staff_have_no_access_to_chat(): void
    {
        $this->send($this->patient, $this->therapist, ['content' => 'confidential'])->assertCreated();
        $conversation = Conversation::firstOrFail();

        $this->as($this->admin)->getJson('/api/v1/chat')->assertForbidden();
        $this->as($this->admin)->getJson("/api/v1/chat/{$this->patient->id}")->assertForbidden();
        $this->send($this->admin, $this->patient, ['content' => 'hi'])->assertForbidden();

        $this->assertFalse(app(ChatService::class)->canSubscribe($this->admin, $conversation->id));
    }

    public function test_allowed_pair_without_history_sees_an_empty_thread(): void
    {
        $this->as($this->patient)->getJson("/api/v1/chat/{$this->therapist->id}")
            ->assertOk()
            ->assertJsonPath('conversation', null)
            ->assertJsonPath('data', []);

        $this->as($this->patient)->postJson("/api/v1/chat/{$this->therapist->id}/read")
            ->assertOk()->assertJsonPath('updated', 0);

        $this->assertSame(0, Conversation::count());
    }

    public function test_unapproved_or_inactive_therapist_cannot_chat(): void
    {
        Therapist::whereKey($this->therapist->id)->update(['approval_status' => ApprovalStatus::PENDING->value]);
        $this->send($this->patient, $this->therapist, ['content' => 'hi'])->assertNotFound();

        Therapist::whereKey($this->therapist->id)->update(['approval_status' => ApprovalStatus::APPROVED->value]);
        User::whereKey($this->therapist->id)->update(['is_active' => false]);
        $this->send($this->patient, $this->therapist, ['content' => 'hi'])->assertNotFound();

        $this->assertSame(0, Conversation::count());
    }

    public function test_thread_closes_when_care_relationship_ends_but_history_stays_readable(): void
    {
        $this->send($this->patient, $this->therapist, ['content' => 'before switch'])->assertCreated();

        // Patient moves to another therapist.
        Patient::whereKey($this->patient->id)->update(['therapist_id' => $this->otherTherapist->id]);

        $this->send($this->patient, $this->therapist, ['content' => 'after switch'])->assertStatus(409);
        $this->send($this->therapist, $this->patient, ['content' => 'after switch'])->assertStatus(409);

        $conversation = Conversation::firstOrFail();
        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->status);
        $this->assertNotNull($conversation->closed_at);
        $this->assertSame(1, $conversation->messages()->count());

        // Both still read the history; the therapist may also mark it read.
        $this->as($this->patient)->getJson("/api/v1/chat/{$this->therapist->id}")
            ->assertOk()->assertJsonPath('conversation.status', 'closed')->assertJsonCount(1, 'data');
        $this->as($this->therapist)->getJson("/api/v1/chat/{$this->patient->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->therapist)->postJson("/api/v1/chat/{$this->patient->id}/read")->assertOk()->assertJsonPath('updated', 1);

        // And a new thread opens with the new therapist.
        $this->send($this->patient, $this->otherTherapist, ['content' => 'hello new doctor'])->assertCreated();
        $this->assertSame(2, Conversation::count());
    }

    // ----------------------------------------------------------------- content

    public function test_empty_message_and_oversized_text_are_rejected(): void
    {
        $this->send($this->patient, $this->therapist, [])->assertStatus(422)->assertJsonValidationErrors('content');
        $this->send($this->patient, $this->therapist, ['content' => "   \n "])->assertStatus(422)->assertJsonValidationErrors('content');
        $this->send($this->patient, $this->therapist, ['content' => str_repeat('a', 4001)])->assertStatus(422)->assertJsonValidationErrors('content');

        $this->assertSame(0, Message::count());
        $this->assertSame(0, Conversation::count());
    }

    public function test_read_receipts_only_touch_messages_addressed_to_the_reader(): void
    {
        Event::fake([MessagesRead::class]);

        $this->send($this->patient, $this->therapist, ['content' => 'one'])->assertCreated();
        $this->send($this->patient, $this->therapist, ['content' => 'two'])->assertCreated();
        $this->send($this->therapist, $this->patient, ['content' => 'reply'])->assertCreated();

        // The patient marking read must not mark their own outgoing messages.
        $this->as($this->patient)->postJson("/api/v1/chat/{$this->therapist->id}/read")->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame(2, Message::where('is_read', false)->where('receiver_id', $this->therapist->id)->count());

        $this->as($this->therapist)->postJson("/api/v1/chat/{$this->patient->id}/read")->assertOk()->assertJsonPath('updated', 2);
        $this->as($this->therapist)->postJson("/api/v1/chat/{$this->patient->id}/read")->assertOk()->assertJsonPath('updated', 0);

        $this->assertSame(0, Message::where('is_read', false)->count());
        $this->assertNotNull(Message::first()->read_at);
        Event::assertDispatched(MessagesRead::class, 2);
    }

    public function test_receiver_is_notified_once_per_unread_burst(): void
    {
        $this->send($this->patient, $this->therapist, ['content' => 'one'])->assertCreated();
        $this->send($this->patient, $this->therapist, ['content' => 'two'])->assertCreated();
        $this->send($this->patient, $this->therapist, ['content' => 'three'])->assertCreated();

        $this->assertSame(1, $this->therapist->notifications()->where('type', ChatMessageReceivedNotification::class)->count());
        $this->assertSame(0, $this->patient->notifications()->count());

        $data = $this->therapist->notifications()->first()->data;
        $this->assertArrayNotHasKey('content', $data);
        $this->assertSame($this->patient->id, $data['sender_id']);

        // Once read, the next message alerts again.
        $this->as($this->therapist)->postJson("/api/v1/chat/{$this->patient->id}/read")->assertOk();
        $this->send($this->patient, $this->therapist, ['content' => 'four'])->assertCreated();
        $this->assertSame(2, $this->therapist->notifications()->count());
    }

    public function test_messages_are_paginated_newest_first(): void
    {
        foreach (range(1, 25) as $i) {
            $this->send($this->patient, $this->therapist, ['content' => "m{$i}"])->assertCreated();
        }

        $this->as($this->therapist)->getJson("/api/v1/chat/{$this->patient->id}?per_page=10")
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonPath('data.0.content', 'm25');

        $this->as($this->therapist)->getJson("/api/v1/chat/{$this->patient->id}?per_page=10&page=3")
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.4.content', 'm1');
    }

    public function test_idempotency_key_prevents_duplicate_sends(): void
    {
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'chat-send-0001'];

        $first = $this->as($this->patient)->post("/api/v1/chat/{$this->therapist->id}", ['content' => 'once'], $headers)->assertCreated();
        $second = $this->as($this->patient)->post("/api/v1/chat/{$this->therapist->id}", ['content' => 'once'], $headers)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Message::count());
    }

    // ------------------------------------------------------------- attachments

    public function test_image_audio_and_document_attachments_are_stored_privately(): void
    {
        $image = $this->send($this->patient, $this->therapist, ['attachment' => UploadedFile::fake()->image('photo.png', 20, 20)])
            ->assertCreated()
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.attachment.type', 'image')
            ->assertJsonPath('data.attachment.mime_type', 'image/png')
            ->assertJsonPath('data.attachment.name', 'photo.png');

        $wav = "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xac\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00";
        $this->send($this->therapist, $this->patient, [
            'content' => 'voice note',
            'attachment' => $this->disguised($wav, 'note.wav', 'audio/wav'),
        ])->assertCreated()
            ->assertJsonPath('data.content', 'voice note')
            ->assertJsonPath('data.attachment.type', 'audio');

        $pdf = $this->send($this->patient, $this->therapist, [
            'attachment' => $this->disguised("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", '../../etc/passwd.pdf', 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.attachment.type', 'file')
            ->assertJsonPath('data.attachment.mime_type', 'application/pdf');

        $this->assertSame('passwd.pdf', $pdf->json('data.attachment.name'));

        $conversation = Conversation::firstOrFail();

        foreach (Message::all() as $message) {
            $this->assertStringStartsWith("chat/{$conversation->id}/", $message->file_path);
            $this->assertStringNotContainsString('photo', $message->file_path);
            $this->assertStringNotContainsString('..', $message->file_path);
            Storage::disk('local')->assertExists($message->file_path);
        }

        // Storage paths never leave the server; only the download route does.
        $this->assertStringNotContainsString('chat/', json_encode($image->json()));
        $this->assertStringContainsString('/api/v1/chat/attachments/', $image->json('data.attachment.download_url'));

        $this->assertSame(3, DB::table('audit_logs')->where('action', 'chat.attachment_sent')->count());
    }

    public function test_attachment_type_is_decided_by_content_not_by_name_or_declared_mime(): void
    {
        // PHP source disguised as an image.
        $this->send($this->patient, $this->therapist, [
            'attachment' => $this->disguised('<?php echo 1;', 'evil.png', 'image/png'),
        ])->assertStatus(422)->assertJsonValidationErrors('attachment');

        // HTML disguised as a PDF.
        $this->send($this->patient, $this->therapist, [
            'attachment' => $this->disguised('<html><script>alert(1)</script></html>', 'report.pdf', 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('attachment');

        // Executable / archive types are not on the allow-list at all.
        $this->send($this->patient, $this->therapist, [
            'attachment' => $this->disguised("MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff", 'setup.exe', 'application/octet-stream'),
        ])->assertStatus(422)->assertJsonValidationErrors('attachment');

        $this->assertSame(0, Message::count());
        $this->assertSame(0, Conversation::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_attachment_size_is_capped_per_kind(): void
    {
        config(['sakina.chat.attachment_max_kb.image' => 1]);

        $this->send($this->patient, $this->therapist, [
            'attachment' => UploadedFile::fake()->image('big.png', 400, 400)->size(2048),
        ])->assertStatus(422)->assertJsonValidationErrors('attachment');

        $this->assertSame(0, Message::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_the_two_participants_can_download_an_attachment(): void
    {
        $response = $this->send($this->patient, $this->therapist, [
            'attachment' => UploadedFile::fake()->image('scan.jpg', 20, 20),
        ])->assertCreated();

        $messageId = $response->json('data.id');
        $path = Message::findOrFail($messageId)->file_path;

        $this->as($this->therapist)->get("/api/v1/chat/attachments/{$messageId}", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename=scan.jpg');
        $this->as($this->patient)->get("/api/v1/chat/attachments/{$messageId}", ['Accept' => 'application/json'])->assertOk();

        $this->as($this->otherTherapist)->getJson("/api/v1/chat/attachments/{$messageId}")->assertNotFound();
        $this->as($this->otherPatient)->getJson("/api/v1/chat/attachments/{$messageId}")->assertNotFound();
        $this->as($this->admin)->getJson("/api/v1/chat/attachments/{$messageId}")->assertForbidden();

        // The generic files endpoint applies the same participant-only rule (staff included).
        $this->as($this->therapist)->getJson('/api/v1/files/download/'.$path)->assertOk();
        $this->as($this->otherTherapist)->getJson('/api/v1/files/download/'.$path)->assertNotFound();
        $this->as($this->admin)->getJson('/api/v1/files/download/'.$path)->assertNotFound();

        $this->assertDatabaseHas('audit_logs', ['action' => 'file.downloaded', 'user_id' => $this->therapist->id]);
    }

    public function test_attachment_is_removed_when_the_message_cannot_be_saved(): void
    {
        $this->send($this->patient, $this->therapist, ['content' => 'open thread'])->assertCreated();
        // Closing the thread between validation and the locked write makes the insert refuse.
        Patient::whereKey($this->patient->id)->update(['therapist_id' => null]);

        $this->send($this->patient, $this->therapist, [
            'attachment' => UploadedFile::fake()->image('late.png', 10, 10),
        ])->assertStatus(409);

        $this->assertSame(1, Message::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ---------------------------------------------------------------- realtime

    public function test_private_channels_admit_participants_only(): void
    {
        // The null/log broadcasters skip channel auth; exercise the real (Reverb) one offline.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'localhost',
        ]);
        // Channel callbacks were registered on the boot-time driver; bind them to this one.
        require base_path('routes/channels.php');

        $this->postJson('/api/v1/broadcasting/auth', ['channel_name' => 'private-chat.conversation.x', 'socket_id' => '1234.5678'])->assertUnauthorized();

        $this->send($this->patient, $this->therapist, ['content' => 'hi'])->assertCreated();
        $conversation = Conversation::firstOrFail();

        $channel = "private-chat.conversation.{$conversation->id}";

        $this->as($this->patient)->postJson('/api/v1/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])->assertOk();
        $this->as($this->therapist)->postJson('/api/v1/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])->assertOk();
        $this->as($this->otherTherapist)->postJson('/api/v1/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])->assertForbidden();
        $this->as($this->admin)->postJson('/api/v1/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])->assertForbidden();

        $own = "private-App.Models.User.{$this->patient->id}";
        $this->as($this->patient)->postJson('/api/v1/broadcasting/auth', ['channel_name' => $own, 'socket_id' => '1234.5678'])->assertOk();
        $this->as($this->therapist)->postJson('/api/v1/broadcasting/auth', ['channel_name' => $own, 'socket_id' => '1234.5678'])->assertForbidden();
    }

    public function test_events_target_the_conversation_and_receiver_channels_without_leaking_paths(): void
    {
        $this->send($this->patient, $this->therapist, ['attachment' => UploadedFile::fake()->image('x.png', 10, 10)])->assertCreated();
        $message = Message::firstOrFail();

        $event = new MessageSent($message);
        $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());

        $this->assertSame([
            "private-chat.conversation.{$message->conversation_id}",
            "private-App.Models.User.{$this->therapist->id}",
        ], $channels);
        $this->assertSame('chat.message.sent', $event->broadcastAs());
        $this->assertStringNotContainsString('chat/', json_encode($event->broadcastWith()));
    }

    public function test_a_broken_broadcaster_does_not_fail_the_send(): void
    {
        Broadcast::shouldReceive('connection')->andThrow(new \RuntimeException('reverb down'));
        Broadcast::shouldReceive('queue')->andThrow(new \RuntimeException('reverb down'));

        $this->send($this->patient, $this->therapist, ['content' => 'still delivered'])->assertCreated();
        $this->assertSame(1, Message::count());
    }

    // ------------------------------------------------------------- anonymizer

    public function test_anonymizing_a_participant_removes_their_threads_and_attachments(): void
    {
        $this->send($this->patient, $this->therapist, ['content' => 'my secret'])->assertCreated();
        $this->send($this->therapist, $this->patient, ['attachment' => UploadedFile::fake()->image('plan.png', 10, 10)])->assertCreated();
        $this->send($this->otherPatient, $this->otherTherapist, ['content' => 'unrelated'])->assertCreated();

        $conversation = Conversation::where('patient_id', $this->patient->id)->firstOrFail();
        $this->assertNotEmpty(Storage::disk('local')->allFiles("chat/{$conversation->id}"));

        app(AccountAnonymizer::class)->anonymize($this->patient);

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles("chat/{$conversation->id}"));

        // The other pair's thread is untouched.
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Message::count());
    }
}
