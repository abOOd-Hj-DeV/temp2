<?php

namespace App\Services\Patient;

use App\Models\Patient;
use App\Models\Program;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\AssessmentRepositoryInterface;
use App\Repositories\Contracts\PatientRepositoryInterface;
use Illuminate\Validation\ValidationException;

/**
 * Read-models for the patient home screens: dashboard, progress,
 * appointments, and program content.
 */
class PatientDashboardService
{
    public function __construct(
        private PatientRepositoryInterface $patients,
        private AssessmentRepositoryInterface $assessments,
    ) {}

    public function dashboard(User $user): array
    {
        $patient = $this->requireProfile($user);
        $latest = $this->assessments->getLatestByPatientId($patient->user_id);
        $upcoming = $this->upcomingSessions($patient, 1)->first();

        return [
            'patient' => [
                'full_name' => $patient->full_name,
                'compliance_level' => $patient->compliance_level,
            ],
            'latest_assessment' => $latest ? [
                'type' => $latest->type->value,
                'score' => $latest->score,
                'completed_at' => $latest->completed_at?->toISOString(),
            ] : null,
            'next_session' => $upcoming ? $this->sessionToArray($upcoming) : null,
            'quick_actions' => ['take_assessment', 'log_mood', 'view_programs'],
        ];
    }

    public function progress(User $user): array
    {
        $patient = $this->requireProfile($user);

        $history = $this->assessments->findByPatientId($patient->user_id);

        return [
            'assessment_count' => $history->count(),
            'recent_scores' => $history->take(10)->map(fn ($a) => [
                'type' => $a->type->value,
                'score' => $a->score,
                'completed_at' => $a->completed_at?->toDateString(),
            ])->values()->all(),
            'current_score' => $patient->assessment_score,
            'compliance_level' => $patient->compliance_level,
        ];
    }

    public function appointments(User $user): array
    {
        $patient = $this->requireProfile($user);

        return [
            'upcoming' => $this->upcomingSessions($patient)
                ->map(fn (TherapySession $s) => $this->sessionToArray($s))
                ->all(),
            'past' => TherapySession::where('patient_id', $patient->user_id)
                ->whereIn('status', ['completed', 'cancelled'])
                ->orderByDesc('session_date')
                ->limit(10)
                ->get()
                ->map(fn (TherapySession $s) => $this->sessionToArray($s))
                ->all(),
        ];
    }

    public function programs(User $user): array
    {
        $this->requireProfile($user);

        return [
            'programs' => Program::with(['modules' => fn ($q) => $q->orderBy('order')])
                ->orderBy('is_core', 'desc')
                ->get()
                ->map(fn (Program $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'is_core' => (bool) $p->is_core,
                    'modules_count' => $p->modules->count(),
                ])
                ->all(),
        ];
    }

    private function requireProfile(User $user): Patient
    {
        $patient = $this->patients->findByUserId($user->id);

        if (! $patient) {
            throw ValidationException::withMessages([
                'profile' => __('Please complete your profile first.'),
            ]);
        }

        return $patient;
    }

    private function upcomingSessions(Patient $patient, ?int $limit = null)
    {
        return TherapySession::where('patient_id', $patient->user_id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('session_date', '>=', now()->toDateString())
            ->orderBy('session_date')
            ->orderBy('session_time')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();
    }

    private function sessionToArray(TherapySession $session): array
    {
        return [
            'id' => $session->id,
            'therapist_id' => $session->therapist_id,
            'session_date' => $session->session_date?->toDateString(),
            'session_time' => $session->session_time,
            'medium' => $session->medium,
            'status' => $session->status,
            'is_initial' => (bool) $session->is_initial,
            'payment_status' => $session->payment_status,
            'price' => $session->price,
        ];
    }
}
