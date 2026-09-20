<?php

namespace App\Services\Patient;

use App\Models\Payment;
use App\Models\RedFlag;
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
            $user->revokeAllTokens();
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
                    'therapist_id' => $s->therapist_id,
                    'session_date' => $s->session_date?->toDateString(),
                    'session_time' => $s->session_time ? substr((string) $s->session_time, 0, 5) : null,
                    'medium' => $s->medium,
                    'status' => $s->status,
                    'payment_status' => $s->payment_status,
                    'price' => $s->price,
                    'summary' => $s->summary,
                ])->all()
                : [],
            'mood_logs' => $patient
                ? $patient->moodLogs()->orderBy('log_date')->get()->map(fn ($m) => [
                    'log_date' => $m->log_date?->toDateString(),
                    'score' => $m->score,
                    'notes' => $m->notes,
                ])->all()
                : [],
            'red_flags' => $patient
                ? RedFlag::where('patient_id', $patient->user_id)->orderBy('created_at')->get()->map(fn ($f) => [
                    'id' => $f->id,
                    'assessment_id' => $f->assessment_id,
                    'type' => $f->type?->value,
                    'priority' => $f->priority?->value,
                    'status' => $f->status,
                    'description' => $f->description,
                    'action_taken' => $f->action_taken,
                    'previous_flag_id' => $f->previous_flag_id,
                    'created_at' => $f->created_at?->toISOString(),
                    'updated_at' => $f->updated_at?->toISOString(),
                ])->all()
                : [],
            'subscriptions' => $patient
                ? $patient->subscriptions()->get()->map(fn ($s) => [
                    'id' => $s->id,
                    'type' => $s->type,
                    'start_date' => $s->start_date?->toDateString(),
                    'end_date' => $s->end_date?->toDateString(),
                    'price' => $s->price,
                    'verification_status' => $s->verification_status,
                ])->all()
                : [],
            'payments' => $patient
                ? Payment::query()
                    ->where(fn ($q) => $q
                        ->whereIn('subscription_id', $patient->subscriptions()->select('id'))
                        ->orWhereIn('therapy_session_id', $patient->sessions()->select('id')))
                    ->get()
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'subscription_id' => $p->subscription_id,
                        'therapy_session_id' => $p->therapy_session_id,
                        'amount' => $p->amount,
                        'status' => $p->status,
                        'note' => $p->note,
                        'reviewed_at' => $p->reviewed_at?->toISOString(),
                        'created_at' => $p->created_at?->toISOString(),
                    ])->all()
                : [],
            'notifications' => $user->notifications()->get()->map(fn ($n) => [
                'id' => $n->id,
                'type' => class_basename($n->type),
                'data' => $n->data,
                'read_at' => $n->read_at?->toISOString(),
                'created_at' => $n->created_at?->toISOString(),
            ])->all(),
        ];
    }
}
