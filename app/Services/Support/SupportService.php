<?php

namespace App\Services\Support;

use App\Enums\SupportType;
use App\Enums\UserRole;
use App\Exceptions\ConflictException;
use App\Models\Support;
use App\Models\SupportReply;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Files\SecureFileService;
use App\Services\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Patient support tickets: open a request (optional attachment), exchange
 * replies with support staff, staff triage/assign/close them from the admin
 * dashboard. Replies are only accepted while the ticket is open.
 */
class SupportService
{
    /** Roles a ticket may be assigned to. */
    private const ASSIGNABLE = [
        UserRole::SUPER_ADMIN,
        UserRole::ADMIN,
        UserRole::CLINICAL_SUPERVISOR,
        UserRole::SUPPORT_AGENT,
    ];

    public function __construct(
        private SecureFileService $files,
        private AuditLogService $audit,
        private NotificationService $notifications,
    ) {}

    public function create(User $user, array $data, ?UploadedFile $file): Support
    {
        $path = null;
        if ($file) {
            $stored = $this->files->upload($user, $file, 'support');
            $path = $stored['path'];
        }

        $ticket = Support::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => SupportType::from($data['type'])->value,
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'],
            'file_path' => $path,
            'status' => 'open',
        ]);

        $this->audit->record($user, AuditLogService::SUPPORT_TICKET_CREATED, $ticket->id);

        return $ticket;
    }

    public function mine(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return Support::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function show(User $user, string $id): Support
    {
        $ticket = Support::whereKey($id)
            ->when(! $this->isStaff($user), fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if (! $ticket) {
            throw new NotFoundHttpException('Support ticket not found.');
        }

        return $ticket->load('replies.author:id,name,role');
    }

    public function listAll(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return Support::with('user:id,name,whatsapp_number,email')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['assigned_to'] ?? null, fn ($q, $a) => $q->where('assigned_to', $a))
            ->orderBy('status')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function assign(User $admin, string $id, string $agentId): Support
    {
        $ticket = $this->findOrFail($id);

        $agent = User::find($agentId);
        if (! $agent || ! in_array($agent->role, self::ASSIGNABLE, true)) {
            throw ValidationException::withMessages([
                'assigned_to' => 'Ticket can only be assigned to a staff member.',
            ]);
        }

        $ticket->update(['assigned_to' => $agent->id]);
        $this->audit->record($admin, AuditLogService::SUPPORT_TICKET_ASSIGNED, $ticket->id, [
            'assigned_to' => $agent->id,
        ]);

        return $ticket->refresh();
    }

    public function setStatus(User $actor, string $id, string $status): Support
    {
        $ticket = $this->findOrFail($id);

        $ticket->update(['status' => $status]);
        $this->audit->record($actor, AuditLogService::SUPPORT_TICKET_STATUS, $ticket->id, [
            'status' => $status,
        ]);

        return $ticket->refresh();
    }

    /** Owner or staff adds a reply; the ticket must still be open. */
    public function reply(User $author, string $id, string $body): SupportReply
    {
        $ticket = $this->show($author, $id);

        if ($ticket->status !== 'open') {
            throw new ConflictException('This ticket is closed. Open a new ticket to continue.');
        }

        $reply = DB::transaction(function () use ($author, $ticket, $body) {
            $reply = SupportReply::create([
                'support_id' => $ticket->id,
                'user_id' => $author->id,
                'is_staff' => $this->isStaff($author),
                'body' => trim($body),
            ]);
            $ticket->touch();

            $this->audit->record($author, AuditLogService::SUPPORT_TICKET_REPLIED, $ticket->id, [
                'reply_id' => $reply->id,
                'from_staff' => $reply->is_staff,
            ]);

            return $reply;
        });

        $this->notifications->deliver('supportReplied', $reply->load('ticket'));

        return $reply;
    }

    public function replyToArray(SupportReply $reply): array
    {
        return [
            'id' => $reply->id,
            'user_id' => $reply->user_id,
            'from_staff' => $reply->is_staff,
            'author_name' => $reply->relationLoaded('author') ? $reply->author?->name : null,
            'body' => $reply->body,
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }

    public function toArray(Support $ticket): array
    {
        return [
            'id' => $ticket->id,
            'user_id' => $ticket->user_id,
            'type' => $ticket->type,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'has_attachment' => $ticket->file_path !== null,
            'status' => $ticket->status,
            'assigned_to' => $ticket->assigned_to,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'user' => $ticket->relationLoaded('user') ? $ticket->user : null,
            'replies' => $ticket->relationLoaded('replies')
                ? $ticket->replies->map(fn (SupportReply $r) => $this->replyToArray($r))->values()->all()
                : null,
        ];
    }

    private function findOrFail(string $id): Support
    {
        return Support::find($id) ?? throw new NotFoundHttpException('Support ticket not found.');
    }

    private function isStaff(User $user): bool
    {
        return in_array($user->role, [
            UserRole::SUPER_ADMIN,
            UserRole::ADMIN,
            UserRole::CLINICAL_SUPERVISOR,
            UserRole::SUPPORT_AGENT,
        ], true);
    }
}
