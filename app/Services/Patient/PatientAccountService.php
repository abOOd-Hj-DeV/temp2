<?php

namespace App\Services\Patient;

use App\Models\User;
use App\Repositories\Contracts\AssessmentRepositoryInterface;
use App\Repositories\Contracts\PatientRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Account lifecycle: scheduled deletion (30-day grace) and GDPR-style
 * self-service data export.
 */
class PatientAccountService
{
    private const DELETION_GRACE_DAYS = 30;

    public function __construct(
        private UserRepositoryInterface $users,
        private PatientRepositoryInterface $patients,
        private AssessmentRepositoryInterface $assessments,
        private AuditLogService $audit,
    ) {}

    /**
     * Revoke every token now; PruneScheduledDeletionsJob anonymises the
     * account after the grace period. The account stays "active" so that
     * logging back in within the window cancels the request.
     */
    public function requestDeletion(User $user): array
    {
        $scheduledAt = now()->addDays(self::DELETION_GRACE_DAYS);

        DB::transaction(function () use ($user, $scheduledAt) {
            $this->users->update($user, ['deletion_scheduled_at' => $scheduledAt]);

            // Deactivate every live session token immediately.
            $user->tokens()->delete();
        });

        $this->audit->record($user, AuditLogService::ACCOUNT_DELETION_REQUESTED, $user->id, [
            'scheduled_at' => $scheduledAt->toISOString(),
        ]);
        Log::info('Account deletion scheduled', ['user_id' => $user->id]);

        return [
            'message' => __('Account deletion scheduled. Your personal data will be anonymised after :days days unless you log back in.', [
                'days' => self::DELETION_GRACE_DAYS,
            ]),
            'deletion_scheduled_at' => $scheduledAt->toISOString(),
        ];
    }

    public function exportData(User $user): array
    {
        $patient = $this->patients->findByUserId($user->id);

        return [
            'exported_at' => now()->toISOString(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'whatsapp_number' => $user->whatsapp_number,
                'role' => $user->role->value,
                'created_at' => $user->created_at?->toISOString(),
            ],
            'patient_profile' => $patient ? [
                'full_name' => $patient->full_name,
                'age' => $patient->age,
                'gender' => $patient->gender,
                'language' => $patient->language,
                'assessment_score' => $patient->assessment_score,
                'compliance_level' => $patient->compliance_level,
            ] : null,
            'assessments' => $patient
                ? $this->assessments->findByPatientId($patient->user_id)->map(fn ($a) => [
                    'id' => $a->id,
                    'type' => $a->type->value,
                    'score' => $a->score,
                    'answers' => $a->answers,
                    'completed_at' => $a->completed_at?->toISOString(),
                ])->all()
                : [],
            'sessions' => $patient
                ? $patient->sessions()->get()->map(fn ($s) => [
                    'id' => $s->id,
                    'session_date' => $s->session_date?->toDateString(),
                    'medium' => $s->medium,
                    'status' => $s->status,
                    'price' => $s->price,
                ])->all()
                : [],
        ];
    }
}
