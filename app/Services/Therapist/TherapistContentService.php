<?php

namespace App\Services\Therapist;

use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapistContent;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Therapist's reusable content library on top of the fixed programme
 * library. Every item the therapist writes is owned by him and can be shared
 * with any number of his clients (therapist_content_assignments); patients
 * only see items shared with them. Un-sharing never deletes the item.
 */
class TherapistContentService
{
    public function __construct(
        private TherapistClientService $clients,
        private AuditLogService $audit,
    ) {}

    public function library(
        Therapist $therapist,
        int $perPage = 15,
        ?string $patientId = null,
        ?string $search = null,
        ?string $contentType = null,
    ): LengthAwarePaginator {
        return TherapistContent::where('therapist_id', $therapist->user_id)
            ->when($patientId, fn ($q) => $q->whereHas('assignedPatients', fn ($p) => $p->where('patients.user_id', $patientId)))
            ->when($contentType, fn ($q) => $q->where('content_type', $contentType))
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $w->where('title', 'like', $like)->orWhere('body', 'like', $like);
            }))
            ->with('assignedPatients:user_id,full_name')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /** Create a library item; optionally share it right away with clients. */
    public function create(Therapist $therapist, array $data): TherapistContent
    {
        $patientIds = $this->targetIds($data);
        $patients = $this->requireClients($therapist, $patientIds);

        $item = DB::transaction(function () use ($therapist, $data, $patients) {
            $item = TherapistContent::create([
                'id' => (string) Str::uuid(),
                'therapist_id' => $therapist->user_id,
                'patient_id' => $patients->first()?->user_id,
                'title' => $data['title'],
                'content_type' => $data['content_type'],
                'body' => $data['body'] ?? null,
                'url' => $data['url'] ?? null,
            ]);

            $this->audit->record($therapist->user, AuditLogService::CONTENT_ITEM_CREATED, $item->id);

            if ($patients->isNotEmpty()) {
                $this->attach($therapist, $item, $patients);
            }

            return $item;
        });

        return $item;
    }

    public function show(Therapist $therapist, string $id): TherapistContent
    {
        return $this->findOrFail($therapist, $id);
    }

    /** Edits the item itself; sharing is managed through assign()/unassign(). */
    public function update(Therapist $therapist, string $id, array $data): TherapistContent
    {
        $item = $this->findOrFail($therapist, $id);

        $item->update([
            'title' => $data['title'] ?? $item->title,
            'content_type' => $data['content_type'] ?? $item->content_type,
            'body' => array_key_exists('body', $data) ? $data['body'] : $item->body,
            'url' => array_key_exists('url', $data) ? $data['url'] : $item->url,
        ]);

        $this->audit->record($therapist->user, AuditLogService::CONTENT_ITEM_UPDATED, $item->id);

        return $item->refresh();
    }

    public function delete(Therapist $therapist, string $id): void
    {
        $item = $this->findOrFail($therapist, $id);
        $item->delete();

        $this->audit->record($therapist->user, AuditLogService::CONTENT_ITEM_DELETED, $id);
    }

    /** Share an existing library item with more clients (idempotent). */
    public function assign(Therapist $therapist, string $id, array $patientIds): TherapistContent
    {
        $item = $this->findOrFail($therapist, $id);
        $patients = $this->requireClients($therapist, $patientIds);

        DB::transaction(fn () => $this->attach($therapist, $item, $patients));

        return $item->refresh();
    }

    public function unassign(Therapist $therapist, string $id, string $patientId): TherapistContent
    {
        $item = $this->findOrFail($therapist, $id);

        $detached = $item->assignedPatients()->detach($patientId);

        if ($detached === 0) {
            throw new NotFoundHttpException('This item is not shared with that client.');
        }

        if ($item->patient_id === $patientId) {
            $item->update(['patient_id' => $item->assignedPatients()->value('patients.user_id')]);
        }

        $this->audit->record($therapist->user, AuditLogService::CONTENT_ITEM_UNASSIGNED, $item->id, [
            'patient_id' => $patientId,
        ]);

        return $item->refresh();
    }

    /** Patient-side: everything shared with this patient. */
    public function forPatient(Patient $patient, int $perPage = 15): LengthAwarePaginator
    {
        return TherapistContent::whereHas('assignedPatients', fn ($q) => $q->where('patients.user_id', $patient->user_id))
            ->with('therapist:user_id,full_name')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /** @param Collection<int, Patient> $patients */
    private function attach(Therapist $therapist, TherapistContent $item, Collection $patients): void
    {
        $already = $item->assignedPatients()->pluck('patients.user_id')->all();
        $new = $patients->reject(fn (Patient $p) => in_array($p->user_id, $already, true));

        if ($new->isEmpty()) {
            return;
        }

        $item->assignedPatients()->attach(
            $new->mapWithKeys(fn (Patient $p) => [$p->user_id => ['id' => (string) Str::uuid(), 'assigned_at' => now()]])->all(),
        );

        if ($item->patient_id === null) {
            $item->update(['patient_id' => $new->first()->user_id]);
        }

        $this->audit->record($therapist->user, AuditLogService::CONTENT_ITEM_ASSIGNED, $item->id, [
            'patient_ids' => $new->pluck('user_id')->values()->all(),
        ]);
    }

    /** Accepts `patient_id` (legacy single) and/or `patient_ids[]`. */
    private function targetIds(array $data): array
    {
        $ids = $data['patient_ids'] ?? [];
        if (! empty($data['patient_id'])) {
            $ids[] = $data['patient_id'];
        }

        return array_values(array_unique($ids));
    }

    /** @return Collection<int, Patient> */
    private function requireClients(Therapist $therapist, array $patientIds): Collection
    {
        return collect($patientIds)->map(fn (string $id) => $this->clients->requireClient($therapist, $id));
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
        $assigned = $item->relationLoaded('assignedPatients') ? $item->assignedPatients : null;

        return [
            'id' => $item->id,
            'title' => $item->title,
            'content_type' => $item->content_type,
            'body' => $item->body,
            'url' => $item->url,
            'assigned_patients' => $assigned?->map(fn (Patient $p) => [
                'id' => $p->user_id,
                'name' => $p->full_name,
                'assigned_at' => $p->pivot?->assigned_at,
            ])->values()->all(),
            'assigned_count' => $assigned?->count(),
            'therapist' => $item->relationLoaded('therapist') && $item->therapist ? [
                'id' => $item->therapist->user_id,
                'name' => $item->therapist->full_name,
            ] : null,
            'created_at' => $item->created_at?->toISOString(),
        ];
    }
}
