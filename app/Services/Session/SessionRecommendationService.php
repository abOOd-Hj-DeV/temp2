<?php

namespace App\Services\Session;

use App\Enums\SessionStatus;
use App\Models\Package;
use App\Models\Patient;
use App\Models\Program;
use App\Models\SessionRecommendation;
use App\Models\TherapySession;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * After a completed session the therapist can hand the patient a structured
 * next step — a published package and/or a program plus a short note — that
 * the patient sees on the post-session screen. The free-text session report
 * stays separate (SessionService::report).
 */
class SessionRecommendationService
{
    public function __construct(
        private NotificationService $notifications,
        private AuditLogService $audit,
    ) {}

    /**
     * @param  array{package_id?: string|null, program_id?: string|null, note?: string|null}  $data
     */
    public function save(TherapySession $session, User $therapist, array $data): SessionRecommendation
    {
        if ($therapist->id !== $session->therapist_id) {
            throw ValidationException::withMessages(['session' => 'This session belongs to another therapist.']);
        }

        if ($session->status !== SessionStatus::COMPLETED) {
            throw ValidationException::withMessages(['status' => 'Recommendations can only be written for completed sessions.']);
        }

        $packageId = $data['package_id'] ?? null;
        $programId = $data['program_id'] ?? null;
        $note = isset($data['note']) ? trim((string) $data['note']) : null;
        $note = $note === '' ? null : $note;

        if ($packageId === null && $programId === null && $note === null) {
            throw ValidationException::withMessages(['package_id' => 'Recommend a package, a program, or write a note.']);
        }

        if ($packageId !== null && ! Package::published()->whereKey($packageId)->exists()) {
            throw ValidationException::withMessages(['package_id' => 'The selected package is not available.']);
        }

        if ($programId !== null && ! Program::whereKey($programId)->exists()) {
            throw ValidationException::withMessages(['program_id' => 'The selected program does not exist.']);
        }

        $recommendation = DB::transaction(function () use ($session, $therapist, $packageId, $programId, $note) {
            $existing = SessionRecommendation::where('session_id', $session->id)->lockForUpdate()->first();

            if ($existing === null) {
                $recommendation = SessionRecommendation::create([
                    'session_id' => $session->id,
                    'therapist_id' => $session->therapist_id,
                    'patient_id' => $session->patient_id,
                    'package_id' => $packageId,
                    'program_id' => $programId,
                    'note' => $note,
                    'revision' => 1,
                ]);
            } else {
                $existing->update([
                    'package_id' => $packageId,
                    'program_id' => $programId,
                    'note' => $note,
                    'revision' => $existing->revision + 1,
                ]);
                $recommendation = $existing->refresh();
            }

            $this->audit->record($therapist, AuditLogService::SESSION_RECOMMENDATION_SAVED, $recommendation->id, [
                'session_id' => $session->id,
                'revision' => $recommendation->revision,
                'package_id' => $packageId,
                'program_id' => $programId,
            ]);

            return $recommendation;
        });

        $this->notifications->deliver('sessionRecommendationSaved', $recommendation);

        return $recommendation;
    }

    public function forSession(TherapySession $session): ?SessionRecommendation
    {
        return SessionRecommendation::with(['package', 'program', 'therapist'])->where('session_id', $session->id)->first();
    }

    /** Newest first — what the patient's "next steps" view lists. */
    public function forPatient(Patient $patient, int $limit = 10): Collection
    {
        return SessionRecommendation::with(['package', 'program', 'therapist', 'session'])
            ->where('patient_id', $patient->user_id)
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, 50)))
            ->get();
    }

    public function toArray(SessionRecommendation $r): array
    {
        return [
            'id' => $r->id,
            'session_id' => $r->session_id,
            'therapist_id' => $r->therapist_id,
            'therapist_name' => $r->therapist?->full_name,
            'package' => $r->package === null ? null : [
                'id' => $r->package->id,
                'code' => $r->package->code,
                'name' => $r->package->name,
                'price' => $r->package->price,
                'number_of_sessions' => $r->package->number_of_sessions,
                'duration_days' => $r->package->duration_days,
                'is_published' => $r->package->is_published,
            ],
            'program' => $r->program === null ? null : [
                'id' => $r->program->id,
                'name' => $r->program->name,
                'is_core' => $r->program->is_core,
            ],
            'note' => $r->note,
            'revision' => $r->revision,
            'created_at' => $r->created_at?->toISOString(),
            'updated_at' => $r->updated_at?->toISOString(),
        ];
    }
}
