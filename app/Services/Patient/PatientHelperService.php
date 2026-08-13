<?php
// app/Services/Patient/PatientHelperService.php

namespace App\Services\Patient;

use App\Models\Patient;

class PatientHelperService
{
    public function calculateProfileCompletion(?Patient $patient): int
    {
        if (!$patient) {
            return 0;
        }

        $fields = [
            'full_name' => ['weight' => 20, 'check' => !empty($patient->full_name)],
            'age' => ['weight' => 20, 'check' => !empty($patient->age) && $patient->age >= 18],
            'gender' => ['weight' => 20, 'check' => !empty($patient->gender)],
            'language' => ['weight' => 20, 'check' => !empty($patient->language)],
            'assessment_score' => ['weight' => 10, 'check' => !is_null($patient->assessment_score)],
            'compliance_level' => ['weight' => 10, 'check' => !empty($patient->compliance_level)],
        ];

        $completed = 0;
        foreach ($fields as $field) {
            if ($field['check']) {
                $completed += $field['weight'];
            }
        }

        return min($completed, 100);
    }

    public function checkSafetyConcerns(array $data): array
    {
        $concerns = [];

        if (isset($data['age']) && $data['age'] > 80) {
            $concerns[] = [
                'type' => 'age_concern',
                'message' => 'Patient is over 80 years old',
                'priority' => 'medium'
            ];
        }

        if (isset($data['assessment_score']) && $data['assessment_score'] > 20) {
            $concerns[] = [
                'type' => 'high_assessment_score',
                'message' => 'Assessment score indicates potential risk',
                'priority' => 'high'
            ];
        }

        if (isset($data['safety_flag']) && $data['safety_flag']) {
            $concerns[] = [
                'type' => 'safety_flag_raised',
                'message' => 'Safety flag is raised',
                'priority' => 'high'
            ];
        }

        return [
            'has_concern' => !empty($concerns),
            'concerns' => $concerns,
            'action_required' => !empty(array_filter($concerns, fn($c) => $c['priority'] === 'high'))
        ];
    }

    public function formatPatientForResponse(Patient $patient): array
    {
        return [
            'user_id' => $patient->user_id,
            'full_name' => $patient->full_name,
            'age' => $patient->age,
            'gender' => $patient->gender,
            'language' => $patient->language,
            'assessment_score' => $patient->assessment_score,
            'safety_flag' => (bool) $patient->safety_flag,
            'compliance_level' => $patient->compliance_level,
            'therapist_id' => $patient->therapist_id,
            'subscription_id' => $patient->subscription_id,
            'profile_created' => !is_null($patient->created_at),
            'profile_updated' => $patient->updated_at->diffForHumans(),
            'age_group' => $this->getAgeGroup($patient->age)
        ];
    }

    public function getCompletionLevel(int $percentage): string
    {
        return match(true) {
            $percentage >= 90 => 'complete',
            $percentage >= 70 => 'good',
            $percentage >= 50 => 'moderate',
            $percentage >= 30 => 'basic',
            default => 'incomplete'
        };
    }

    public function getNextProfileSteps(?Patient $patient): array
    {
        $steps = [];

        if (!$patient || empty($patient->full_name)) {
            $steps[] = [
                'step' => 'add_full_name',
                'title' => 'Add your full name',
                'description' => 'Enter your complete name',
                'priority' => 'high'
            ];
        }

        if (!$patient || empty($patient->age)) {
            $steps[] = [
                'step' => 'add_age',
                'title' => 'Add your age',
                'description' => 'Enter your age (must be 18+)',
                'priority' => 'high'
            ];
        }

        if (!$patient || empty($patient->gender)) {
            $steps[] = [
                'step' => 'select_gender',
                'title' => 'Select your gender',
                'description' => 'Choose male, female, or other',
                'priority' => 'medium'
            ];
        }

        if (!$patient || empty($patient->language)) {
            $steps[] = [
                'step' => 'select_language',
                'title' => 'Select preferred language',
                'description' => 'Choose your preferred language for therapy',
                'priority' => 'medium'
            ];
        }

        if (!$patient || is_null($patient->assessment_score)) {
            $steps[] = [
                'step' => 'complete_assessment',
                'title' => 'Complete initial assessment',
                'description' => 'Take the PHQ-9 assessment',
                'priority' => 'medium'
            ];
        }

        return $steps;
    }

    public function getAgeGroup(int $age): string
    {
        return match(true) {
            $age >= 65 => 'senior',
            $age >= 45 => 'middle_aged',
            $age >= 30 => 'young_adult',
            $age >= 18 => 'adult',
            default => 'invalid'
        };
    }

    public function getWelcomeMessage(Patient $patient): string
    {
        $hour = now()->hour;
        $greeting = match(true) {
            $hour < 12 => 'صباح الخير',
            $hour < 18 => 'مساء الخير',
            default => 'مساء الخير'
        };

        $name = $patient->full_name ?: 'عزيزي المريض';

        return "{$greeting} {$name}، كيف تشعر اليوم؟";
    }

    public function getAssessmentSeverity(int $score): string
    {
        return match(true) {
            $score >= 20 => 'شديد',
            $score >= 15 => 'متوسط',
            $score >= 10 => 'خفيف',
            $score >= 5 => 'طفيف',
            default => 'طبيعي'
        };
    }

    public function getAssessmentRecommendation(int $score): string
    {
        return match(true) {
            $score >= 20 => 'نوصي بجلسات أسبوعية مع متابعة مكثفة',
            $score >= 15 => 'نوصي بجلسات أسبوعية منتظمة',
            $score >= 10 => 'نوصي بجلسات كل أسبوعين',
            $score >= 5 => 'نوصي بجلسات شهرية للوقاية',
            default => 'تابع على نفس النمط'
        };
    }

    public function getDashboardStats(Patient $patient): array
    {
        return [
            'completed_sessions' => [
                'count' => 0,
                'label' => 'الجلسات المكتملة',
                'trend' => 'stable',
                'icon' => 'calendar-check'
            ],
            'mood_average' => [
                'value' => 7,
                'label' => 'متوسط المزاج',
                'trend' => 'up',
                'icon' => 'smile'
            ],
            'program_progress' => [
                'percentage' => 25,
                'label' => 'تقدم البرنامج',
                'trend' => 'up',
                'icon' => 'trending-up'
            ],
            'days_in_therapy' => [
                'count' => $patient->created_at->diffInDays(now()),
                'label' => 'أيام في العلاج',
                'trend' => 'up',
                'icon' => 'clock'
            ]
        ];
    }

    public function getQuickActions(Patient $patient): array
    {
        return [
            [
                'id' => 'log_mood',
                'title' => 'سجل مزاجك',
                'description' => 'كيف تشعر اليوم؟',
                'icon' => 'smile',
                'action' => '/mood/log',
                'color' => 'primary',
                'available' => true
            ],
            [
                'id' => 'book_session',
                'title' => 'احجز جلسة',
                'description' => 'جدول موعد مع معالجك',
                'icon' => 'calendar-plus',
                'action' => '/sessions/book',
                'color' => 'success',
                'available' => !is_null($patient->therapist_id)
            ]
        ];
    }

    public function getDashboardRecommendations(Patient $patient): array
    {
        $recommendations = [];

        if (is_null($patient->assessment_score)) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'أكمل التقييم الأولي',
                'description' => 'سيساعدنا على فهم احتياجاتك بشكل أفضل',
                'action' => '/assessment',
                'icon' => 'clipboard'
            ];
        }

        if (is_null($patient->therapist_id)) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'اختر معالجاً',
                'description' => 'اختر من قائمة المعالجين المعتمدين',
                'action' => '/therapists',
                'icon' => 'user-check'
            ];
        }

        return $recommendations;
    }

    public function getAssessmentProgress(Patient $patient): array
    {
        if (is_null($patient->assessment_score)) {
            return [
                'completed' => false,
                'score' => null,
                'status' => 'not_started'
            ];
        }

        return [
            'completed' => true,
            'score' => $patient->assessment_score,
            'status' => $this->getAssessmentSeverity($patient->assessment_score),
            'recommendation' => $this->getAssessmentRecommendation($patient->assessment_score)
        ];
    }
}
