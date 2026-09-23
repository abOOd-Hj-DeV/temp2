<?php

namespace App\Services\Support;

use App\Enums\SupportType;
use App\Enums\UserRole;
use App\Models\Support;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Files\SecureFileService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Patient support tickets: open a request (optional attachment), staff
 * triage/assign/close them from the admin dashboard.
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

        return $ticket;
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
            'user' => $ticket->relationLoaded('user') ? $ticket->user : null,
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
