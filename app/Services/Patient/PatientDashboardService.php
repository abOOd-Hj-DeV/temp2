<?php
// app/Services/Patient/PatientDashboardService.php

namespace App\Services\Patient;

use App\Models\User;
use App\Models\Patient;
use App\Repositories\Contracts\PatientRepositoryInterface;
use Illuminate\Support\Facades\Log;

class PatientDashboardService
{
    public function __construct(
        private PatientRepositoryInterface $patientRepository,
        private PatientHelperService $helperService
    ) {}

    public function getDashboardData(User $user): array
    {
        try {
            $patient = $this->patientRepository->findByUserId($user->id);

            if (!$patient) {
                return [
                    'has_profile' => false,
                    'message' => 'Please complete your profile to access dashboard',
                    'redirect_to' => '/profile'
                ];
            }

            return [
                'has_profile' => true,
                'welcome_message' => $this->helperService->getWelcomeMessage($patient),
                'stats' => $this->helperService->getDashboardStats($patient),
                'upcoming_sessions' => $this->getUpcomingSessions($patient),
                'recent_activities' => $this->getRecentActivities($patient),
                'treatment_progress' => $this->getTreatmentProgress($patient),
                'quick_actions' => $this->helperService->getQuickActions($patient),
                'recommendations' => $this->helperService->getDashboardRecommendations($patient)
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get dashboard data', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function getUpcomingSessions(Patient $patient): array
    {
        return [];
    }

    private function getRecentActivities(Patient $patient): array
    {
        return [];
    }

    private function getTreatmentProgress(Patient $patient): array
    {
        $assessmentScore = $patient->assessment_score ?? 0;

        return [
            'assessment' => [
                'current_score' => $assessmentScore,
                'severity' => $this->helperService->getAssessmentSeverity($assessmentScore),
                'recommendation' => $this->helperService->getAssessmentRecommendation($assessmentScore)
            ],
            'modules' => [
                'completed' => 0,
                'total' => 8,
                'percentage' => 0
            ]
        ];
    }
}
