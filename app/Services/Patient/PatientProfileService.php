<?php

namespace App\Services\Patient;

use App\Models\Patient;
use App\Models\User;
use App\Repositories\Contracts\PatientRepositoryInterface;

/**
 * Patient profile CRUD and onboarding state.
 *
 * Only safe, self-reported fields are writable here; clinical fields
 * (assessment_score, safety_flag, compliance_level, therapist_id,
 * subscription_id) are owned by clinical/admin services.
 */
class PatientProfileService
{
    public const EDITABLE_FIELDS = ['full_name', 'age', 'gender', 'language'];

    public function __construct(
        private PatientRepositoryInterface $patients,
    ) {}

    public function getProfile(User $user): ?array
    {
        $patient = $this->patients->findByUserId($user->id);

        return $patient ? $this->toArray($patient) : null;
    }

    /**
     * Create-or-update the patient's profile (idempotent onboarding).
     */
    public function upsertProfile(User $user, array $data): array
    {
        $fields = array_intersect_key($data, array_flip(self::EDITABLE_FIELDS));

        $patient = $this->patients->findByUserId($user->id);

        if ($patient) {
            $this->patients->update($patient, $fields);
        } else {
            $patient = $this->patients->create($fields + ['user_id' => $user->id]);
        }

        return [
            'message' => __('Profile saved.'),
            'patient' => $this->toArray($patient->refresh()),
            'profile_completion' => $this->completion($patient),
        ];
    }

    public function getOnboarding(User $user): array
    {
        $patient = $this->patients->findByUserId($user->id);

        return [
            'has_profile' => $patient !== null,
            'patient' => $patient ? $this->toArray($patient) : null,
            'profile_completion' => $this->completion($patient),
            'next_steps' => $this->nextSteps($patient),
        ];
    }

    private function completion(?Patient $patient): int
    {
        if (! $patient) {
            return 0;
        }

        $filled = collect(self::EDITABLE_FIELDS)
            ->filter(fn (string $field) => ! empty($patient->{$field}))
            ->count();

        return (int) round($filled / count(self::EDITABLE_FIELDS) * 100);
    }

    private function nextSteps(?Patient $patient): array
    {
        $steps = [];

        if (! $patient) {
            $steps[] = 'complete_profile';
        }

        $steps[] = 'take_assessment';
        $steps[] = 'choose_therapist';

        return $steps;
    }

    private function toArray(Patient $patient): array
    {
        return [
            'user_id' => $patient->user_id,
            'full_name' => $patient->full_name,
            'age' => $patient->age,
            'gender' => $patient->gender,
            'language' => $patient->language,
            'compliance_level' => $patient->compliance_level,
            'has_therapist' => $patient->therapist_id !== null,
            'has_subscription' => $patient->subscription_id !== null,
        ];
    }
}
