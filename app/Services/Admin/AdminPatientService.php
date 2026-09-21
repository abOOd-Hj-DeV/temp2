<?php

namespace App\Services\Admin;

use App\Enums\SessionStatus;
use App\Models\Assessment;
use App\Models\Patient;
use App\Models\RedFlag;
use App\Models\Subscription;
use App\Models\TherapySession;
use App\Repositories\Contracts\AssessmentRepositoryInterface;
use App\Repositories\Contracts\SessionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff read-model of the patient roster. Exposes care-management facts
 * (therapist, subscription, risk, activity) and deliberately nothing from
 * mood notes, assessment answers, safety plans or chat.
 */
class AdminPatientService
{
    public function __construct(
        private AssessmentRepositoryInterface $assessments,
        private SessionRepositoryInterface $sessions,
    ) {}

    /**
     * @param  array{search?: string, therapist_id?: string, safety_flag?: bool, compliance_level?: string, is_active?: bool, unassigned?: bool}  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Patient::query()
            ->with(['user:id,name,email,whatsapp_number,is_active,last_login,deletion_scheduled_at,created_at', 'therapist:user_id,full_name'])
            ->withCount([
                'sessions as upcoming_sessions_count' => fn (Builder $q) => $q
                    ->whereIn('status', [SessionStatus::PENDING->value, SessionStatus::CONFIRMED->value])
                    ->where('session_date', '>=', now()->toDateString()),
            ])
            ->join('users', 'users.id', '=', 'patients.user_id')
            ->select('patients.*')
            ->orderByDesc('patients.created_at');

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('patients.full_name', 'like', $like)
                ->orWhere('users.email', 'like', $like)
                ->orWhere('users.whatsapp_number', 'like', $like));
        }

        if (! empty($filters['therapist_id'])) {
            $query->where('patients.therapist_id', $filters['therapist_id']);
        }

        if (! empty($filters['unassigned'])) {
            $query->whereNull('patients.therapist_id');
        }

        if (array_key_exists('safety_flag', $filters) && $filters['safety_flag'] !== null) {
            $query->where('patients.safety_flag', (bool) $filters['safety_flag']);
        }

        if (! empty($filters['compliance_level'])) {
            $query->where('patients.compliance_level', $filters['compliance_level']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('users.is_active', (bool) $filters['is_active']);
        }

        return $query->paginate($perPage)->through(fn (Patient $patient) => $this->summary($patient));
    }

    public function find(string $userId): ?Patient
    {
        return Patient::with(['user', 'therapist:user_id,full_name,specialty'])->find($userId);
    }

    public function detail(Patient $patient): array
    {
        $latest = $this->assessments->getLatestByPatientId($patient->user_id);

        $subscription = Subscription::where('patient_id', $patient->user_id)
            ->where('verification_status', 'approved')
            ->orderByDesc('end_date')
            ->first();

        $sessionsByStatus = TherapySession::where('patient_id', $patient->user_id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return $this->summary($patient) + [
            'age' => $patient->age,
            'gender' => $patient->gender,
            'language' => $patient->language,
            'assessment_score' => $patient->assessment_score,
            'latest_assessment' => $latest instanceof Assessment ? [
                'id' => $latest->id,
                'type' => $latest->type->value,
                'score' => $latest->score,
                'completed_at' => $latest->completed_at?->toISOString(),
            ] : null,
            'assessments_count' => Assessment::where('patient_id', $patient->user_id)->count(),
            'active_subscription' => $subscription ? [
                'id' => $subscription->id,
                'type' => $subscription->type,
                'package_id' => $subscription->package_id,
                'sessions_total' => $subscription->sessions_total,
                // Same quota rule as SessionService: non-cancelled sessions within the term.
                'sessions_used' => $this->sessions->countNonCancelledForPatientInRange(
                    $patient->user_id,
                    $subscription->start_date?->toDateString() ?? now()->toDateString(),
                    $subscription->end_date?->toDateString() ?? now()->toDateString(),
                ),
                'start_date' => $subscription->start_date?->toDateString(),
                'end_date' => $subscription->end_date?->toDateString(),
                'is_active' => $subscription->is_active,
            ] : null,
            'sessions_by_status' => collect(SessionStatus::values())
                ->mapWithKeys(fn (string $status) => [$status => (int) ($sessionsByStatus[$status] ?? 0)])
                ->all(),
            'red_flags' => [
                'open' => RedFlag::where('patient_id', $patient->user_id)->where('status', 'open')->count(),
                'resolved' => RedFlag::where('patient_id', $patient->user_id)->where('status', 'resolved')->count(),
            ],
        ];
    }

    private function summary(Patient $patient): array
    {
        return [
            'id' => $patient->user_id,
            'full_name' => $patient->full_name,
            'email' => $patient->user?->email,
            'whatsapp_number' => $patient->user?->whatsapp_number,
            'is_active' => (bool) $patient->user?->is_active,
            'deletion_scheduled_at' => $patient->user?->deletion_scheduled_at?->toISOString(),
            'last_login' => $patient->user?->last_login?->toISOString(),
            'joined_at' => $patient->created_at?->toISOString(),
            'safety_flag' => (bool) $patient->safety_flag,
            'compliance_level' => $patient->compliance_level,
            'therapist' => $patient->therapist ? [
                'id' => $patient->therapist->user_id,
                'full_name' => $patient->therapist->full_name,
            ] : null,
            'upcoming_sessions_count' => (int) ($patient->upcoming_sessions_count ?? 0),
        ];
    }
}
