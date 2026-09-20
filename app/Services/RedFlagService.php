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
use Illuminate\Support\Facades\DB;
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
        return $this->createOrMerge($assessment->patient_id, $assessment->id, $type, $priority, $description);
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
        return $this->createOrMerge($patient->user_id, null, $type, $priority, $description);
    }

    /**
     * One open flag per (patient, type): a repeated signal while the first is
     * still open raises the existing flag's priority instead of creating a
     * duplicate. An open flag nobody has touched for `red_flag_stale_days` is
     * closed as superseded and a fresh flag linked to it is opened instead.
     * The patient row is locked first so concurrent signals are serialised;
     * red_flags_open_per_patient_type_unique is the backstop.
     */
    private function createOrMerge(
        string $patientId,
        ?string $assessmentId,
        RedFlagType $type,
        RedFlagPriority $priority,
        string $description,
    ): RedFlag {
        $flag = DB::transaction(function () use ($patientId, $assessmentId, $type, $priority, $description): RedFlag {
            Patient::where('user_id', $patientId)->lockForUpdate()->first();
            $existing = $this->openFlag($patientId, $type);

            if ($existing && ! $this->isStale($existing)) {
                return $this->merge($existing, $assessmentId, $priority);
            }

            if ($existing) {
                $this->redFlags->update($existing, [
                    'status' => 'resolved',
                    'action_taken' => 'Superseded by a new flag: no activity for '.config('sakina.red_flag_stale_days', 30).' days.',
                ]);
            }

            $created = $this->redFlags->create([
                'patient_id' => $patientId,
                'assessment_id' => $assessmentId,
                'type' => $type->value,
                'description' => $description,
                'priority' => $priority->value,
                'assigned_to' => $existing?->assigned_to ?? $this->defaultAssignee()?->id,
                'status' => 'open',
                'previous_flag_id' => $existing?->id,
            ]);

            $created->wasRecentlyCreated = true;

            return $created;
        });

        if ($flag->wasRecentlyCreated) {
            $this->notifications->deliver('redFlagRaised', $flag);
        } elseif ($flag->priorityRaised) {
            $this->notifications->deliver('redFlagPriorityRaised', $flag);
        }

        Log::info('Red flag '.($flag->wasRecentlyCreated ? 'created' : 'merged'), [
            'red_flag_id' => $flag->id,
            'priority' => $flag->priority->value,
        ]);

        return $flag;
    }

    private function openFlag(string $patientId, RedFlagType $type): ?RedFlag
    {
        return RedFlag::query()
            ->where('patient_id', $patientId)
            ->where('type', $type->value)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();
    }

    private function isStale(RedFlag $flag): bool
    {
        $days = (int) config('sakina.red_flag_stale_days', 30);
        $lastActivity = $flag->updated_at ?? $flag->created_at;

        return $days > 0 && $lastActivity !== null && $lastActivity->lt(now()->subDays($days));
    }

    private function merge(RedFlag $existing, ?string $assessmentId, RedFlagPriority $priority): RedFlag
    {
        $update = [];

        if ($this->rank($priority) > $this->rank($existing->priority)) {
            $update['priority'] = $priority->value;
            $existing->priorityRaised = true;
        }

        if ($assessmentId !== null && $existing->assessment_id === null) {
            $update['assessment_id'] = $assessmentId;
        }

        if ($update !== []) {
            $this->redFlags->update($existing, $update);
            $existing->refresh();
        }

        $existing->wasRecentlyCreated = false;

        return $existing;
    }

    private function rank(RedFlagPriority $priority): int
    {
        return match ($priority) {
            RedFlagPriority::LOW => 1,
            RedFlagPriority::MEDIUM => 2,
            RedFlagPriority::HIGH => 3,
        };
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

        if (in_array($user->role, self::CLINICAL_STAFF_ROLES, true)) {
            return $user;
        }

        if ($user->role !== UserRole::THERAPIST) {
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
            'previous_flag_id' => $flag->previous_flag_id,
            'created_at' => $flag->created_at?->toISOString(),
            'updated_at' => $flag->updated_at?->toISOString(),
        ];
    }
}
