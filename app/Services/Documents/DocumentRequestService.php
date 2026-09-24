<?php

namespace App\Services\Documents;

use App\Enums\UserRole;
use App\Exceptions\ConflictException;
use App\Models\DocumentRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Files\SecureFileService;
use App\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff → therapist document workflow. Files live on the private uploads
 * disk under uploads/{therapist_id}/document_requests and are served only
 * through SecureFileService (owner + staff).
 */
class DocumentRequestService
{
    public const UPLOAD_PURPOSE = 'document_requests';

    public function __construct(
        private SecureFileService $files,
        private AuditLogService $audit,
        private NotificationService $notifications,
    ) {}

    public function request(User $staff, array $data): DocumentRequest
    {
        $target = User::whereKey($data['user_id'])->first();

        if (! $target || $target->role !== UserRole::THERAPIST) {
            throw ValidationException::withMessages(['user_id' => 'Documents can currently be requested from therapists only.']);
        }

        $duplicate = DocumentRequest::where('user_id', $target->id)
            ->where('doc_type', $data['doc_type'])
            ->whereIn('status', [DocumentRequest::STATUS_REQUESTED, DocumentRequest::STATUS_SUBMITTED])
            ->exists();

        if ($duplicate) {
            throw new ConflictException('An open request for this document type already exists for this user.');
        }

        $request = DB::transaction(function () use ($staff, $target, $data) {
            $request = DocumentRequest::create([
                'user_id' => $target->id,
                'requested_by' => $staff->id,
                'doc_type' => $data['doc_type'],
                'reason' => $data['reason'] ?? null,
                'status' => DocumentRequest::STATUS_REQUESTED,
                'timestamp' => now(),
            ]);

            $this->audit->record($staff, AuditLogService::DOCUMENT_REQUESTED, $request->id, [
                'user_id' => $target->id, 'doc_type' => $request->doc_type,
            ]);

            return $request;
        });

        $this->notifications->deliver('documentRequested', $request);

        return $request;
    }

    public function upload(User $owner, string $id, UploadedFile $file): DocumentRequest
    {
        $request = DB::transaction(function () use ($owner, $id, $file) {
            $request = DocumentRequest::whereKey($id)->where('user_id', $owner->id)->lockForUpdate()->first()
                ?? throw new NotFoundHttpException('Document request not found.');

            if (! $request->acceptsUpload()) {
                throw new ConflictException("This request is {$request->status} and no longer accepts uploads.");
            }

            $previous = $request->file_path;
            $stored = $this->files->upload($owner, $file, self::UPLOAD_PURPOSE);

            $request->update([
                'file_path' => $stored['path'],
                'original_name' => $stored['original_name'],
                'mime_type' => $stored['mime_type'],
                'status' => DocumentRequest::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'reviewer_id' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ]);

            if ($previous && $previous !== $stored['path']) {
                Storage::disk($this->files->disk())->delete($previous);
            }

            $this->audit->record($owner, AuditLogService::DOCUMENT_SUBMITTED, $request->id, [
                'doc_type' => $request->doc_type,
            ]);

            return $request->refresh();
        });

        $this->notifications->deliver('documentSubmitted', $request);

        return $request;
    }

    public function review(User $staff, string $id, bool $approve, ?string $note): DocumentRequest
    {
        $request = DB::transaction(function () use ($staff, $id, $approve, $note) {
            $request = DocumentRequest::whereKey($id)->lockForUpdate()->first()
                ?? throw new NotFoundHttpException('Document request not found.');

            if ($request->status !== DocumentRequest::STATUS_SUBMITTED) {
                throw new ConflictException("Only submitted documents can be reviewed; this one is {$request->status}.");
            }

            if (! $approve && ($note === null || trim($note) === '')) {
                throw ValidationException::withMessages(['note' => 'A note is required when rejecting a document.']);
            }

            $path = (string) $request->file_path;
            if ($path === '' || ! Storage::disk($this->files->disk())->exists($path)) {
                throw ValidationException::withMessages([
                    'document' => 'The uploaded file is missing; reject the request so the user can upload it again.',
                ]);
            }

            $request->update([
                'status' => $approve ? DocumentRequest::STATUS_APPROVED : DocumentRequest::STATUS_REJECTED,
                'reviewer_id' => $staff->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $this->audit->record(
                $staff,
                $approve ? AuditLogService::DOCUMENT_APPROVED : AuditLogService::DOCUMENT_REJECTED,
                $request->id,
                ['user_id' => $request->user_id, 'doc_type' => $request->doc_type, 'note' => $note],
            );

            return $request->refresh();
        });

        $this->notifications->deliver('documentReviewed', $request);

        return $request;
    }

    public function listForStaff(?string $status, ?string $userId, int $perPage): LengthAwarePaginator
    {
        return DocumentRequest::query()
            ->with(['user:id,name,role', 'requester:id,name', 'reviewer:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'requested' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function listForOwner(User $owner, int $perPage): LengthAwarePaginator
    {
        return DocumentRequest::where('user_id', $owner->id)
            ->orderByRaw("CASE status WHEN 'rejected' THEN 0 WHEN 'requested' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function showForStaff(string $id): DocumentRequest
    {
        return DocumentRequest::with(['user:id,name,role', 'requester:id,name', 'reviewer:id,name'])->findOrFail($id);
    }

    public function showForOwner(User $owner, string $id): DocumentRequest
    {
        return DocumentRequest::where('user_id', $owner->id)->findOrFail($id);
    }

    public function toArray(DocumentRequest $request, bool $staffView = false): array
    {
        $out = [
            'id' => $request->id,
            'doc_type' => $request->doc_type,
            'status' => $request->status,
            'reason' => $request->reason,
            'review_note' => $request->review_note,
            'has_file' => $request->file_path !== null,
            'original_name' => $request->original_name,
            'mime_type' => $request->mime_type,
            'download_url' => $request->file_path ? url('/api/v1/files/download/'.$request->file_path) : null,
            'requested_at' => $request->created_at?->toIso8601String(),
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'accepts_upload' => $request->acceptsUpload(),
        ];

        if ($staffView) {
            $out += [
                'user_id' => $request->user_id,
                'user_name' => $request->user?->name,
                'requested_by' => $request->requested_by,
                'requested_by_name' => $request->requester?->name,
                'reviewer_id' => $request->reviewer_id,
                'reviewer_name' => $request->reviewer?->name,
            ];
        }

        return $out;
    }
}
