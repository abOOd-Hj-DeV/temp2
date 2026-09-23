<?php

namespace App\Services\Therapist;

use App\Enums\SessionStatus;
use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapistClientNote;
use App\Models\TherapySession;
use App\Services\Session\SessionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Therapist-side view of their clients. A "client" is any patient who is
 * assigned to this therapist or has a non-cancelled session with them.
 * Clinical detail (assessment score, safety flag) is only exposed for
 * confirmed clients — never for arbitrary patient ids.
 */
class TherapistClientService
{
    public function __construct(private SessionService $sessions) {}

    public function list(Therapist $therapist, int $perPage = 15): LengthAwarePaginator
    {
        return $this->clientsQuery($therapist)
            ->with('user:id,is_active')
            ->orderBy('full_name')
            ->paginate($perPage);
    }

    public function show(Therapist $therapist, string $patientId): array
    {
        $patient = $this->requireClient($therapist, $patientId);

        $sessions = TherapySession::where('therapist_id', $therapist->user_id)
            ->where('patient_id', $patient->user_id)
            ->orderByDesc('session_date')->orderByDesc('session_time')
            ->limit(20)
            ->get();

        $latestAssessment = $patient->assessments()->latest('completed_at')->first();

        return [
            'client' => $this->clientToArray($patient),
            'clinical' => [
                'assessment_score' => $patient->assessment_score,
                'safety_flag' => (bool) $patient->safety_flag,
                'compliance_level' => $patient->compliance_level?->value ?? $patient->compliance_level,
                'latest_assessment' => $latestAssessment ? [
                    'type' => $latestAssessment->type?->value ?? $latestAssessment->type,
                    'score' => $latestAssessment->score,
                    'completed_at' => $latestAssessment->completed_at?->toISOString(),
                ] : null,
            ],
            'sessions' => $sessions->map(fn (TherapySession $s) => $this->sessions->toArray($s))->all(),
            'stats' => [
                'total_sessions' => $sessions->count(),
                'completed' => $sessions->where('status', SessionStatus::COMPLETED)->count(),
                'cancelled' => $sessions->where('status', SessionStatus::CANCELLED)->count(),
            ],
        ];
    }

    public function notes(Therapist $therapist, string $patientId): array
    {
        $patient = $this->requireClient($therapist, $patientId);

        $notes = TherapistClientNote::where('therapist_id', $therapist->user_id)
            ->where('patient_id', $patient->user_id)
            ->orderByDesc('created_at')
            ->get();

        $reports = TherapySession::where('therapist_id', $therapist->user_id)
            ->where('patient_id', $patient->user_id)
            ->whereNotNull('summary')
            ->orderByDesc('session_date')
            ->get(['id', 'session_date', 'summary']);

        return [
            'notes' => $notes->map(fn (TherapistClientNote $n) => $this->noteToArray($n))->all(),
            'session_reports' => $reports->map(fn (TherapySession $s) => [
                'session_id' => $s->id,
                'session_date' => $s->session_date?->toDateString(),
                'summary' => $s->summary,
            ])->all(),
        ];
    }

    public function addNote(Therapist $therapist, string $patientId, string $body, ?string $sessionId = null): TherapistClientNote
    {
        $patient = $this->requireClient($therapist, $patientId);

        if ($sessionId !== null) {
            $owns = TherapySession::whereKey($sessionId)
                ->where('therapist_id', $therapist->user_id)
                ->where('patient_id', $patient->user_id)
                ->exists();

            if (! $owns) {
                throw ValidationException::withMessages(['session_id' => 'Session does not belong to this client.']);
            }
        }

        return TherapistClientNote::create([
            'therapist_id' => $therapist->user_id,
            'patient_id' => $patient->user_id,
            'session_id' => $sessionId,
            'body' => $body,
        ]);
    }

    public function clientToArray(Patient $patient): array
    {
        return [
            'id' => $patient->user_id,
            'full_name' => $patient->full_name,
            'age' => $patient->age,
            'gender' => $patient->gender,
            'language' => $patient->language,
            'is_assigned' => $patient->therapist_id !== null,
        ];
    }

    public function noteToArray(TherapistClientNote $note): array
    {
        return [
            'id' => $note->id,
            'patient_id' => $note->patient_id,
            'session_id' => $note->session_id,
            'body' => $note->body,
            'created_at' => $note->created_at?->toISOString(),
        ];
    }

    private function clientsQuery(Therapist $therapist): Builder
    {
        return Patient::query()->where(function (Builder $q) use ($therapist) {
            $q->where('therapist_id', $therapist->user_id)
                ->orWhereExists(function ($sub) use ($therapist) {
                    $sub->selectRaw('1')
                        ->from('therapy_sessions')
                        ->whereColumn('therapy_sessions.patient_id', 'patients.user_id')
                        ->where('therapy_sessions.therapist_id', $therapist->user_id)
                        ->where('therapy_sessions.status', '!=', SessionStatus::CANCELLED->value);
                });
        });
    }

    public function requireClient(Therapist $therapist, string $patientId): Patient
    {
        $patient = $this->clientsQuery($therapist)->whereKey($patientId)->first();

        if (! $patient) {
            // 404 rather than 403 so therapists cannot enumerate patient ids.
            throw new NotFoundHttpException('Client not found.');
        }

        return $patient;
    }
}
