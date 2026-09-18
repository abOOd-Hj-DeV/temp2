<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\RedFlagPriority;
use App\Enums\RedFlagType;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Patient;
use App\Models\RedFlag;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\RedFlagRepositoryInterface;
use Illuminate\Support\Facades\Log;

/**
 * Creates and manages clinical red flags raised by assessments
 * and other safety signals.
 */
class RedFlagService
{
    public const CLINICAL_STAFF_ROLES = [
        UserRole::CLINICAL_SUPERVISOR,
        UserRole::SUPER_ADMIN,
        UserRole::ADMIN,
    ];

    public function __construct(
        private RedFlagRepositoryInterface $redFlags,
        private NotificationService $notifications,
    ) {}

    /**
     * Persist a red flag for a critical assessment and alert the assignee.
     * Never throws: a notification failure must not lose the flag itself.
     */
    public function createFromAssessment(
        Assessment $assessment,
        RedFlagType $type,
        RedFlagPriority $priority,
        string $description,
    ): RedFlag {
        $redFlag = $this->redFlags->create([
            'patient_id' => $assessment->patient_id,
            'assessment_id' => $assessment->id,
            'type' => $type->value,
            'description' => $description,
            'priority' => $priority->value,
            'assigned_to' => $this->defaultAssignee()?->id,
            'status' => 'open',
        ]);

        try {
            $this->notifications->redFlagRaised($redFlag);
        } catch (\Throwable $e) {
            Log::error('Red flag notification failed', [
                'red_flag_id' => $redFlag->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Red flag created', [
            'red_flag_id' => $redFlag->id,
            'patient_id' => $assessment->patient_id,
            'priority' => $priority->value,
        ]);

        return $redFlag;
    }

    /**
     * Red flag that is not tied to an assessment (e.g. a low-mood streak).
     */
    public function createFromMood(
        Patient $patient,
        RedFlagType $type,
        RedFlagPriority $priority,
        string $description,
    ): RedFlag {
        $redFlag = $this->redFlags->create([
            'patient_id' => $patient->user_id,
            'assessment_id' => null,
            'type' => $type->value,
            'description' => $description,
            'priority' => $priority->value,
            'assigned_to' => $this->defaultAssignee()?->id,
            'status' => 'open',
        ]);

        try {
            $this->notifications->redFlagRaised($redFlag);
        } catch (\Throwable $e) {
            Log::error('Red flag notification failed', [
                'red_flag_id' => $redFlag->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Red flag created', [
            'red_flag_id' => $redFlag->id,
            'patient_id' => $patient->user_id,
            'priority' => $priority->value,
        ]);

        return $redFlag;
    }

    public function getPatientRedFlags(string $patientId): array
    {
        return $this->redFlags->findByPatientId($patientId)
            ->map(fn (RedFlag $flag) => $this->toArray($flag))
            ->all();
    }

    public function getOpenRedFlags(array $filters = []): array
    {
        return $this->redFlags->getOpenRedFlags($filters)
            ->map(fn (RedFlag $flag) => $this->toArray($flag))
            ->all();
    }

    public function find(string $redFlagId): ?RedFlag
    {
        return $this->redFlags->findById($redFlagId);
    }

    public function updateStatus(string $redFlagId, string $status, ?string $actionTaken = null): bool
    {
        return $this->redFlags->updateStatus($redFlagId, $status, $actionTaken);
    }

    public function assignTo(string $redFlagId, string $userId): bool
    {
        return $this->redFlags->assignTo($redFlagId, $userId);
    }

    /**
     * Resolve a user who may own the given flag: clinical staff, or an approved
     * therapist who actually treats the patient. Returns null otherwise.
     */
    public function resolveAssignee(RedFlag $flag, string $userId): ?User
    {
        $user = User::whereKey($userId)->where('is_active', true)->first();

        if (! $user) {
            return null;
        }

        if ($user->hasAnyRole(array_map(fn (UserRole $r) => $r->value, self::CLINICAL_STAFF_ROLES))) {
            return $user;
        }

        if (! $user->hasRole(UserRole::THERAPIST->value)) {
            return null;
        }

        $therapist = $user->therapist;

        if (! $therapist || $therapist->approval_status !== ApprovalStatus::APPROVED) {
            return null;
        }

        $treatsPatient = Patient::whereKey($flag->patient_id)->where('therapist_id', $user->id)->exists()
            || TherapySession::where('patient_id', $flag->patient_id)
                ->where('therapist_id', $user->id)
                ->where('status', '!=', SessionStatus::CANCELLED->value)
                ->exists();

        return $treatsPatient ? $user : null;
    }

    /**
     * Reassign a flag and alert the new owner.
     */
    public function reassign(RedFlag $flag, User $assignee): RedFlag
    {
        $this->redFlags->assignTo($flag->id, $assignee->id);
        $flag->refresh();

        try {
            $this->notifications->redFlagRaised($flag);
        } catch (\Throwable $e) {
            Log::error('Red flag reassignment notification failed', [
                'red_flag_id' => $flag->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $flag;
    }

    public function getStats(array $filters = []): array
    {
        return $this->redFlags->getStats($filters);
    }

    /**
     * The first active clinical supervisor owns new flags; falls back to
     * super_admin, then to null (unassigned) when no staff exists yet.
     */
    private function defaultAssignee(): ?User
    {
        foreach (self::CLINICAL_STAFF_ROLES as $role) {
            $assignee = User::where('role', $role->value)->where('is_active', true)->first();
            if ($assignee) {
                return $assignee;
            }
        }

        return null;
    }

    public function toArray(RedFlag $flag): array
    {
        return [
            'id' => $flag->id,
            'patient_id' => $flag->patient_id,
            'type' => $flag->type->value,
            'priority' => $flag->priority->value,
            'status' => $flag->status,
            'description' => $flag->description,
            'action_taken' => $flag->action_taken,
            'assigned_to' => $flag->assigned_to,
            'assessment_id' => $flag->assessment_id,
            'created_at' => $flag->created_at?->toISOString(),
        ];
    }
}
