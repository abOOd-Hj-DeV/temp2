<?php

namespace App\Services\Therapist;

use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapistContent;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-client extra material a therapist writes on top of the fixed programme
 * library. The therapist's "library" is every row he created; patients only
 * see rows addressed to them.
 */
class TherapistContentService
{
    public function __construct(
        private TherapistClientService $clients,
        private AuditLogService $audit,
    ) {}

    public function library(Therapist $therapist, int $perPage = 15, ?string $patientId = null): LengthAwarePaginator
    {
        return TherapistContent::where('therapist_id', $therapist->user_id)
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->with('patient:user_id,full_name')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function create(Therapist $therapist, array $data): TherapistContent
    {
        $patient = $this->clients->requireClient($therapist, $data['patient_id']);

        $item = TherapistContent::create([
            'id' => (string) Str::uuid(),
            'therapist_id' => $therapist->user_id,
            'patient_id' => $patient->user_id,
            'title' => $data['title'],
            'content_type' => $data['content_type'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
        ]);

        $this->audit->record($therapist->user, AuditLogService::CONTENT_ITEM_CREATED, $item->id);

        return $item;
    }

    public function show(Therapist $therapist, string $id): TherapistContent
    {
        return $this->findOrFail($therapist, $id);
    }

    public function update(Therapist $therapist, string $id, array $data): TherapistContent
    {
        $item = $this->findOrFail($therapist, $id);

        $item->update([
            'title' => $data['title'] ?? $item->title,
            'content_type' => $data['content_type'] ?? $item->content_type,
            'body' => array_key_exists('body', $data) ? $data['body'] : $item->body,
            'url' => array_key_exists('url', $data) ? $data['url'] : $item->url,
        ]);

        // Re-targeting a different patient is allowed but still must be a client.
        if (isset($data['patient_id']) && $data['patient_id'] !== $item->patient_id) {
            $patient = $this->clients->requireClient($therapist, $data['patient_id']);
            $item->update(['patient_id' => $patient->user_id]);
        }

        $this->audit->record($therapist->user, AuditLogService::CONTENT_ITEM_UPDATED, $item->id);

        return $item->refresh();
    }

    public function delete(Therapist $therapist, string $id): void
    {
        $item = $this->findOrFail($therapist, $id);
        $item->delete();

        $this->audit->record($therapist->user, AuditLogService::CONTENT_ITEM_DELETED, $id);
    }

    /** Patient-side: everything addressed to this patient. */
    public function forPatient(Patient $patient, int $perPage = 15): LengthAwarePaginator
    {
        return TherapistContent::where('patient_id', $patient->user_id)
            ->with('therapist:user_id,full_name')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    private function findOrFail(Therapist $therapist, string $id): TherapistContent
    {
        $item = TherapistContent::where('therapist_id', $therapist->user_id)->whereKey($id)->first();

        if (! $item) {
            throw new NotFoundHttpException('Content item not found.');
        }

        return $item;
    }

    public function toArray(TherapistContent $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'content_type' => $item->content_type,
            'body' => $item->body,
            'url' => $item->url,
            'patient' => $item->relationLoaded('patient') && $item->patient ? [
                'id' => $item->patient->user_id,
                'name' => $item->patient->full_name,
            ] : null,
            'therapist' => $item->relationLoaded('therapist') && $item->therapist ? [
                'id' => $item->therapist->user_id,
                'name' => $item->therapist->full_name,
            ] : null,
            'created_at' => $item->created_at?->toISOString(),
        ];
    }
}
