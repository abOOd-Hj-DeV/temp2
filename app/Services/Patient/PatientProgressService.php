<?php
// app/Services/Patient/PatientProgressService.php

namespace App\Services\Patient;

use App\Models\User;
use App\Models\Patient;
use App\Repositories\Contracts\PatientRepositoryInterface;
use Illuminate\Support\Facades\Log;

class PatientProgressService
{
    public function __construct(
        private PatientRepositoryInterface $patientRepository,
        private PatientHelperService $helperService
    ) {}

    public function getProgress(User $user): array
    {
        try {
            $patient = $this->patientRepository->findByUserId($user->id);

            if (!$patient) {
                return [
                    'has_profile' => false,
                    'message' => 'Please complete your profile to view progress'
                ];
            }

            return [
                'has_profile' => true,
                'progress' => [
                    'profile_completion' => $this->helperService->calculateProfileCompletion($patient),
                    'assessment_progress' => $this->helperService->getAssessmentProgress($patient),
                    'treatment_duration' => $patient->created_at->diffInDays(now()),
                    'compliance_level' => $patient->compliance_level ?? 'not_set'
                ],
                'milestones' => $this->getProgressMilestones($patient),
                'recommendations' => $this->getProgressRecommendations($patient)
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get patient progress', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function getProgressMilestones(Patient $patient): array
    {
        $milestones = [];

        if ($patient->full_name) {
            $milestones[] = [
                'title' => 'ملف شخصي مكتمل',
                'achieved' => true,
                'date' => $patient->created_at->format('Y-m-d'),
                'icon' => 'user-check'
            ];
        }

        if ($patient->assessment_score) {
            $milestones[] = [
                'title' => 'التقييم الأولي مكتمل',
                'achieved' => true,
                'icon' => 'clipboard-check'
            ];
        }

        if ($patient->therapist_id) {
            $milestones[] = [
                'title' => 'تم اختيار المعالج',
                'achieved' => true,
                'icon' => 'users'
            ];
        }

        return $milestones;
    }

    private function getProgressRecommendations(Patient $patient): array
    {
        $recommendations = [];

        if (!$patient->therapist_id) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'اختر معالجاً',
                'description' => 'ابدأ رحلة العلاج باختيار معالج مناسب'
            ];
        }

        if (!$patient->assessment_score) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'أكمل التقييم',
                'description' => 'التقييم يساعد في تخصيص خطة العلاج'
            ];
        }

        return $recommendations;
    }
}
