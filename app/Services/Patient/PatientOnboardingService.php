<?php
// app/Services/Patient/PatientOnboardingService.php

namespace App\Services\Patient;

use App\Models\User;
use App\Models\Patient;
use App\Repositories\Contracts\PatientRepositoryInterface;
use Illuminate\Support\Facades\Log;

class PatientOnboardingService
{
    public function __construct(
        private PatientRepositoryInterface $patientRepository
    ) {}

    public function getOnboardingData(User $user): array
    {
        try {
            $patient = $this->patientRepository->findByUserId($user->id);
            $completedSteps = $this->getCompletedOnboardingSteps($patient);

            return [
                'steps' => $this->getOnboardingSteps($completedSteps),
                'therapists_preview' => $this->getTherapistsPreview(),
                'programs_preview' => $this->getProgramsPreview(),
                'faqs' => $this->getOnboardingFaqs(),
                'completed_steps' => $completedSteps,
                'next_step' => $this->getNextOnboardingStep($completedSteps)
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get onboarding data', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function getCompletedOnboardingSteps(?Patient $patient): array
    {
        $completed = [];

        if ($patient) {
            if (!empty($patient->full_name) && !empty($patient->age) &&
                !empty($patient->gender) && !empty($patient->language)) {
                $completed[] = 'profile';
            }

            if (!is_null($patient->assessment_score)) {
                $completed[] = 'assessment';
            }

            if (!is_null($patient->therapist_id)) {
                $completed[] = 'therapist_selection';
            }
        }

        return $completed;
    }

    private function getOnboardingSteps(array $completedSteps): array
    {
        return [
            [
                'id' => 1,
                'key' => 'profile',
                'title' => 'أكمل ملفك الشخصي',
                'description' => 'أخبرنا عن نفسك لنساعدك بشكل أفضل',
                'icon' => 'user',
                'completed' => in_array('profile', $completedSteps),
                'action' => '/profile',
                'estimated_time' => '5 دقائق',
                'required' => true
            ],
            [
                'id' => 2,
                'key' => 'assessment',
                'title' => 'التقييم الأولي',
                'description' => 'أجب على بعض الأسئلة لفهم احتياجاتك',
                'icon' => 'clipboard',
                'completed' => in_array('assessment', $completedSteps),
                'action' => '/assessment',
                'estimated_time' => '10 دقائق',
                'required' => true
            ],
            [
                'id' => 3,
                'key' => 'therapist_selection',
                'title' => 'اختر معالجك',
                'description' => 'اختر المعالج المناسب لاحتياجاتك',
                'icon' => 'users',
                'completed' => in_array('therapist_selection', $completedSteps),
                'action' => '/therapists',
                'estimated_time' => '10 دقائق',
                'required' => true
            ]
        ];
    }

    private function getTherapistsPreview(): array
    {
        return [
            [
                'id' => 'therapist_001',
                'name' => 'د. أحمد محمد',
                'specialty' => 'القلق والاكتئاب',
                'experience' => '10 سنوات',
                'languages' => ['العربية', 'الإنجليزية'],
                'rating' => 4.8,
                'available' => true
            ]
        ];
    }

    private function getProgramsPreview(): array
    {
        return [
            [
                'id' => 'program_001',
                'name' => 'برنامج إدارة القلق',
                'description' => 'برنامج مكثف لمدة 8 أسابيع للتعامل مع القلق',
                'duration' => '8 أسابيع',
                'modules_count' => 16
            ]
        ];
    }

    private function getOnboardingFaqs(): array
    {
        return [
            [
                'question' => 'كم مدة الجلسة الواحدة؟',
                'answer' => 'مدة الجلسة 50 دقيقة، تبدأ في الوقت المتفق عليه مع المعالج.'
            ]
        ];
    }

    private function getNextOnboardingStep(array $completedSteps): ?array
    {
        $allSteps = [
            'profile' => 1,
            'assessment' => 2,
            'therapist_selection' => 3
        ];

        foreach ($allSteps as $step => $order) {
            if (!in_array($step, $completedSteps)) {
                return [
                    'step' => $step,
                    'order' => $order,
                    'action_required' => true
                ];
            }
        }

        return null;
    }
}

