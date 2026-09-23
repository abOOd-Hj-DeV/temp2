<?php

namespace App\Services\Chat;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Events\Chat\MessageSent;
use App\Events\Chat\MessagesRead;
use App\Exceptions\ConflictException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Therapist;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Files\SecureFileService;
use App\Services\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Private 1:1 chat between a patient and the therapist currently assigned
 * to them (patients.therapist_id). Nobody else — including staff — can read
 * or write a conversation.
 *
 * A thread is opened lazily on the first message and closed the moment the
 * care relationship ends (therapist switch, deactivation, approval revoked):
 * both participants keep read access to the history, sending is refused.
 *
 * Bodies are encrypted at rest by the model cast; attachments are stored on
 * the private uploads disk under chat/{conversation_id}/ with a generated
 * name and served only through the authorized download path.
 */
class ChatService
{
    public function __construct(
        private SecureFileService $files,
        private NotificationService $notifications,
        private AuditLogService $audit,
    ) {}

    /** Threads the user takes part in, most recently active first. */
    public function conversationsFor(User $user, int $perPage): LengthAwarePaginator
    {
        return Conversation::query()
            ->where($this->participantColumn($user), $user->id)
            ->with(['patient:user_id,full_name', 'therapist:user_id,full_name'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('receiver_id', $user->id)->where('is_read', false)])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * The thread between the actor and a counterpart, or null when none has
     * been opened yet but the pair is allowed to chat. Any other pair — an
     * unrelated user, a stranger, a non-existent id — is a 404 so the caller
     * learns nothing about who exists.
     */
    public function find(User $actor, string $counterpartId): ?Conversation
    {
        [$patientId, $therapistId] = $this->pairFor($actor, $counterpartId);

        $conversation = Conversation::where('patient_id', $patientId)->where('therapist_id', $therapistId)->first();

        if ($conversation === null && ! $this->isCurrentCareRelationship($patientId, $therapistId)) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        return $conversation;
    }

    /** Open (or reuse) the thread; only the current care pair may do so. */
    public function open(User $actor, string $counterpartId): Conversation
    {
        $conversation = $this->find($actor, $counterpartId);

        if ($conversation !== null) {
            return $conversation;
        }

        [$patientId, $therapistId] = $this->pairFor($actor, $counterpartId);

        try {
            $conversation = DB::transaction(fn () => Conversation::create([
                'patient_id' => $patientId,
                'therapist_id' => $therapistId,
                'status' => Conversation::STATUS_ACTIVE,
            ]));
        } catch (UniqueConstraintViolationException) {
            return Conversation::where('patient_id', $patientId)->where('therapist_id', $therapistId)->firstOrFail();
        }

        $this->audit->record($actor, AuditLogService::CONVERSATION_OPENED, $conversation->id, [
            'patient_id' => $patientId, 'therapist_id' => $therapistId,
        ]);

        return $conversation;
    }

    public function messages(User $actor, Conversation $conversation, int $perPage): LengthAwarePaginator
    {
        $this->assertParticipant($actor, $conversation);

        return $conversation->messages()
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Send text and/or one attachment to a counterpart, opening the thread on
     * first contact. Payload validation comes first so a bad request leaves
     * no trace (no thread, no file).
     */
    public function send(User $actor, string $counterpartId, ?string $content, ?UploadedFile $attachment): Message
    {
        $content = $this->normalizeContent($content);

        if ($content === null && $attachment === null) {
            throw ValidationException::withMessages(['content' => __('A message needs text or an attachment.')]);
        }

        $type = $attachment === null ? null : $this->classifyAttachment($attachment);

        $conversation = $this->open($actor, $counterpartId);
        $this->assertSendable($conversation);

        $stored = $attachment === null ? null : $this->storeAttachment($conversation, $attachment, $type);
        $receiverId = $conversation->counterpartId($actor);

        try {
            [$message, $unreadBefore] = DB::transaction(function () use ($actor, $conversation, $content, $stored, $receiverId) {
                $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $this->assertSendable($locked);

                $unreadBefore = $locked->messages()->where('receiver_id', $receiverId)->where('is_read', false)->count();

                $message = $locked->messages()->create([
                    'sender_id' => $actor->id,
                    'receiver_id' => $receiverId,
                    'content' => $content,
                    'timestamp' => now(),
                    'is_read' => false,
                ] + ($stored ?? []));

                $locked->forceFill(['last_message_at' => $message->timestamp])->save();

                return [$message, $unreadBefore];
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                Storage::disk($this->files->disk())->delete($stored['file_path']);
            }

            throw $e;
        }

        if ($stored !== null) {
            $this->audit->record($actor, AuditLogService::CHAT_ATTACHMENT_SENT, $message->id, [
                'conversation_id' => $conversation->id,
                'attachment_type' => $stored['attachment_type'],
                'attachment_mime' => $stored['attachment_mime'],
                'attachment_size' => $stored['attachment_size'],
            ]);
        }

        $this->broadcast(new MessageSent($message));

        if ($unreadBefore === 0) {
            $this->notifications->deliver('chatMessageReceived', $message);
        }

        return $message;
    }

    /** Mark every message addressed to the actor as read; returns how many changed. */
    public function markRead(User $actor, Conversation $conversation): int
    {
        $this->assertParticipant($actor, $conversation);

        $readAt = now();

        $updated = $conversation->messages()
            ->where('receiver_id', $actor->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => $readAt]);

        if ($updated > 0) {
            $this->broadcast(new MessagesRead($conversation->id, $actor->id, $readAt));
        }

        return $updated;
    }

    /** Resolve an attachment the actor may download (participants only); returns the message. */
    public function attachment(User $actor, string $messageId): Message
    {
        $message = Message::query()
            ->whereKey($messageId)
            ->whereNotNull('file_path')
            ->whereHas('conversation', fn ($q) => $q->where($this->participantColumn($actor), $actor->id))
            ->first();

        if ($message === null || ! $this->isChatParticipantRole($actor)) {
            throw new NotFoundHttpException('File not found.');
        }

        $this->files->authorizeDownload($actor, $message->file_path);

        return $message;
    }

    /** Private-channel authorization callback. */
    public function canSubscribe(User $user, string $conversationId): bool
    {
        if (! $user->is_active || $user->phone_verified_at === null || ! $this->isChatParticipantRole($user)) {
            return false;
        }

        return Conversation::whereKey($conversationId)
            ->where($this->participantColumn($user), $user->id)
            ->exists();
    }

    public function conversationToArray(Conversation $conversation, User $viewer): array
    {
        return [
            'id' => $conversation->id,
            'status' => $conversation->status,
            'patient' => ['id' => $conversation->patient_id, 'name' => $conversation->patient?->full_name],
            'therapist' => ['id' => $conversation->therapist_id, 'name' => $conversation->therapist?->full_name],
            'counterpart_id' => $conversation->counterpartId($viewer),
            'unread_count' => (int) ($conversation->unread_count
                ?? $conversation->messages()->where('receiver_id', $viewer->id)->where('is_read', false)->count()),
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'closed_at' => $conversation->closed_at?->toISOString(),
        ];
    }

    public function toArray(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'receiver_id' => $message->receiver_id,
            'content' => $message->content,
            'attachment' => $message->hasAttachment() ? [
                'type' => $message->attachment_type,
                'name' => $message->attachment_name,
                'mime_type' => $message->attachment_mime,
                'size' => $message->attachment_size,
                'download_url' => url("/api/v1/chat/attachments/{$message->id}"),
            ] : null,
            'is_read' => $message->is_read,
            'read_at' => $message->read_at?->toISOString(),
            'sent_at' => $message->timestamp?->toISOString(),
        ];
    }

    /**
     * (patient_id, therapist_id) for the actor and the user they address.
     * Anything other than patient <-> therapist is a 404.
     *
     * @return array{0:string,1:string}
     */
    private function pairFor(User $actor, string $counterpartId): array
    {
        if ($counterpartId === $actor->id) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        return match ($actor->role) {
            UserRole::PATIENT => [$actor->id, $counterpartId],
            UserRole::THERAPIST => [$counterpartId, $actor->id],
            default => throw new NotFoundHttpException('Conversation not found.'),
        };
    }

    /**
     * The pair is allowed to chat right now: the therapist is the one assigned
     * to the patient, both accounts are active and the therapist is approved.
     */
    private function isCurrentCareRelationship(string $patientId, string $therapistId): bool
    {
        $assigned = Patient::whereKey($patientId)
            ->where('therapist_id', $therapistId)
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->where('role', UserRole::PATIENT->value))
            ->exists();

        if (! $assigned) {
            return false;
        }

        return Therapist::whereKey($therapistId)
            ->where('approval_status', ApprovalStatus::APPROVED->value)
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->where('role', UserRole::THERAPIST->value))
            ->exists();
    }

    private function assertParticipant(User $actor, Conversation $conversation): void
    {
        if (! $conversation->isParticipant($actor)) {
            throw new NotFoundHttpException('Conversation not found.');
        }
    }

    /**
     * Sending requires an open thread whose pair is still the active care
     * relationship; a thread that has silently outlived it is closed here.
     */
    private function assertSendable(Conversation $conversation): void
    {
        if ($conversation->isActive() && ! $this->isCurrentCareRelationship($conversation->patient_id, $conversation->therapist_id)) {
            $conversation->forceFill(['status' => Conversation::STATUS_CLOSED, 'closed_at' => now()])->save();
        }

        if (! $conversation->isActive()) {
            throw new ConflictException('This conversation is closed.');
        }
    }

    private function normalizeContent(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        $content = trim(str_replace("\0", '', $content));

        if ($content === '') {
            return null;
        }

        $max = (int) config('sakina.chat.max_message_chars', 4000);

        if (mb_strlen($content) > $max) {
            throw ValidationException::withMessages(['content' => __('Message may not exceed :max characters.', ['max' => $max])]);
        }

        return $content;
    }

    /**
     * Classify by the sniffed MIME type (never the client-declared name or
     * type) and enforce the per-kind size ceiling. Returns image|audio|file.
     */
    private function classifyAttachment(UploadedFile $file): string
    {
        $type = $this->attachmentTypeFor((string) $file->getMimeType());

        if ($type === null) {
            throw ValidationException::withMessages(['attachment' => __('Unsupported attachment type.')]);
        }

        $maxKb = (int) config("sakina.chat.attachment_max_kb.{$type}", 5120);

        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['attachment' => __('The :type may not exceed :max KB.', ['type' => $type, 'max' => $maxKb])]);
        }

        return $type;
    }

    /**
     * Store under the thread's private folder with a generated name.
     *
     * @return array{file_path:string,attachment_type:string,attachment_name:string,attachment_mime:string,attachment_size:int}
     */
    private function storeAttachment(Conversation $conversation, UploadedFile $file, string $type): array
    {
        $path = $file->store("chat/{$conversation->id}", ['disk' => $this->files->disk()]);

        if ($path === false) {
            Log::error('Chat attachment could not be stored', ['conversation_id' => $conversation->id]);

            throw ValidationException::withMessages(['attachment' => __('The attachment could not be stored. Please retry.')]);
        }

        return [
            'file_path' => $path,
            'attachment_type' => $type,
            'attachment_name' => $this->displayName($file),
            'attachment_mime' => (string) $file->getMimeType(),
            'attachment_size' => (int) $file->getSize(),
        ];
    }

    /**
     * The download name keeps the client's base name but carries the extension
     * of the sniffed type, so a PNG uploaded as "scan.pdf" is served as "scan.png".
     */
    private function displayName(UploadedFile $file): string
    {
        $name = SecureFileService::safeOriginalName($file->getClientOriginalName());
        $extension = $file->guessExtension();

        if ($extension === null) {
            return $name;
        }

        $base = pathinfo($name, PATHINFO_FILENAME);

        return ($base === '' ? 'attachment' : $base).'.'.$extension;
    }

    private function attachmentTypeFor(string $mime): ?string
    {
        foreach ((array) config('sakina.chat.attachment_mimes', []) as $type => $mimes) {
            if (in_array($type, Message::ATTACHMENT_TYPES, true) && in_array(strtolower($mime), $mimes, true)) {
                return $type;
            }
        }

        return null;
    }

    private function participantColumn(User $user): string
    {
        return $user->role === UserRole::THERAPIST ? 'therapist_id' : 'patient_id';
    }

    private function isChatParticipantRole(User $user): bool
    {
        return in_array($user->role, [UserRole::PATIENT, UserRole::THERAPIST], true);
    }

    /** Realtime is best effort: a broken broadcaster never fails the request. */
    private function broadcast(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Chat broadcast failed', ['event' => $event::class, 'error' => $e->getMessage()]);
        }
    }
}
