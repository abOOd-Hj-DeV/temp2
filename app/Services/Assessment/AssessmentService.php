<?php
// app/Services/Patient/AssessmentService.php

namespace App\Services\Assessment;

use App\Models\User;
use App\Models\Patient;
use App\Models\Assessment;
use App\Enums\AssessmentType;
use App\Enum\RedFlagPirority;
use App\Repositories\Contracts\AssessmentRepositoryInterface;
use App\Repositories\Contracts\PatientRepositoryInterface;
use App\Services\NotificationService;
use App\Services\RedFlagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssessmentService
{
    public function __construct(
        private AssessmentRepositoryInterface $assessmentRepository,
        private PatientRepositoryInterface $patientRepository,
        private NotificationService $notificationService,
        private RedFlagService $redFlagService
    ) {}

    /**
     * Create a new assessment for authenticated patient
     */
    public function createAssessment(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            try {
                $patient = $this->patientRepository->findByUserId($user->id);

                if (!$patient) {
                    throw new \Exception('Patient profile not found');
                }

                // Validate assessment type
                if (!in_array($data['type'], AssessmentType::values())) {
                    throw new \Exception('Invalid assessment type');
                }

                // Calculate score from answers
                $score = $this->calculateScore($data['type'], $data['answers']);

                // Create assessment (answers will be encrypted automatically in Model)
                $assessment = $this->assessmentRepository->create([
                    'id' => Str::uuid()->toString(),
                    'patient_id' => $patient->user_id,
                    'type' => $data['type'],
                    'score' => $score,
                    'answers' => $data['answers'], // Will be automatically encrypted
                    'completed_at' => now()
                ]);

                // Verify encryption was successful
                $this->verifyEncryption($assessment, $data['answers']);

                // Update patient's assessment score in profile
                $this->updatePatientAssessment($patient, $assessment);

                // Check if red flag is needed
                $redFlag = null;
                if ($this->requiresRedFlag($assessment)) {
                    $redFlag = $this->redFlagService->createFromAssessment($assessment);
                }

                // Send notifications
                $notification = $this->sendAssessmentNotifications($assessment, $patient, $redFlag);

                // Return formatted response
                return [
                    'assessment' => $this->formatAssessmentResponse($assessment),
                    'interpretation' => $this->interpretScore($assessment),
                    'recommendations' => $this->getRecommendations($assessment),
                    'red_flag_created' => !is_null($redFlag),
                    'notification_sent' => $notification['sent']
                ];

            } catch (\Exception $e) {
                Log::error('Failed to create assessment', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }

    /**
     * Get assessment history for authenticated patient
     */
    public function getAssessmentHistory(User $user, array $filters = []): array
    {
        try {
            $patient = $this->patientRepository->findByUserId($user->id);

            if (!$patient) {
                return [
                    'has_profile' => false,
                    'message' => 'Please complete your profile first'
                ];
            }

            $perPage = $filters['per_page'] ?? 10;
            $paginator = $this->assessmentRepository->getPatientHistory($patient->user_id, $perPage);

            return [
                'has_profile' => true,
                'assessments' => $paginator->items(),
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage()
                ],
                'statistics' => $this->getAssessmentStatistics($patient->user_id)
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get assessment history', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get a single decrypted answer (for authorized users only)
     * Used by: Therapist or admin endpoints (future implementation)
     */
    public function getDecryptedAnswer(string $assessmentId, string $questionKey, User $requester): array
    {
        // Check permissions
        if (!$this->hasPermissionToViewAnswers($requester)) {
            throw new \Exception('Unauthorized to view encrypted answers');
        }

        $assessment = $this->assessmentRepository->findById($assessmentId);

        if (!$assessment) {
            throw new \Exception('Assessment not found');
        }

        $answer = $assessment->getAnswer($questionKey);
        $encryptedData = $assessment->getEncryptedAnswersArray();

        return [
            'question' => $questionKey,
            'answer' => $answer,
            'hash' => $encryptedData[$questionKey]['hash'] ?? null,
            'verified' => $answer !== null
        ];
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Calculate total score from answers
     * Used by: createAssessment() to calculate initial score
     */
    private function calculateScore(string $type, array $answers): int
    {
        $score = 0;

        foreach ($answers as $question => $answer) {
            if (is_numeric($answer)) {
                $score += (int) $answer;
            }
        }

        // Ensure score is within allowed limits
        $maxScore = AssessmentType::from($type)->maxScore();
        return min(max($score, 0), $maxScore);
    }

    /**
     * Calculate score from encrypted answers (alternative method)
     * Used by: Future functionality if we need to recalculate from stored encrypted data
     */
    private function calculateScoreFromEncrypted(Assessment $assessment): int
    {
        $encryptedAnswers = $assessment->getEncryptedAnswersArray();
        $score = 0;

        foreach ($encryptedAnswers as $question => $encryptedData) {
            $answer = $assessment->getAnswer($question);
            if ($answer !== null) {
                $score += $answer;
            }
        }

        return $score;
    }

    /**
     * Verify that encryption of answers worked correctly
     * Used by: createAssessment() to validate encryption process
     */
    private function verifyEncryption(Assessment $assessment, array $originalAnswers): void
    {
        // Verify that all answers are encrypted correctly
        if (!$assessment->verifyAllAnswers($originalAnswers)) {
            throw new \Exception('Failed to verify answer encryption');
        }

        // Verify a random answer to be sure
        $sampleQuestion = array_key_first($originalAnswers);
        $sampleAnswer = $originalAnswers[$sampleQuestion];

        if (!$assessment->verifyAnswer($sampleQuestion, $sampleAnswer)) {
            throw new \Exception('Sample answer verification failed');
        }

        Log::info('Assessment encryption verified', [
            'assessment_id' => $assessment->id,
            'questions_count' => count($originalAnswers)
        ]);
    }

    /**
     * Update patient's assessment score in their profile
     * Used by: createAssessment() to sync score with patient profile
     */
    private function updatePatientAssessment(Patient $patient, Assessment $assessment): void
    {
        $this->patientRepository->updateAssessmentScore($patient->user_id, $assessment->score);
    }

    /**
     * Check if assessment score requires a red flag
     * Used by: createAssessment() to determine if red flag should be created
     */
    private function requiresRedFlag(Assessment $assessment): bool
    {
        return $assessment->score >= 15; // Risk threshold
    }

    /**
     * Send notifications about the assessment completion
     * Used by: createAssessment() to notify patient and therapist
     */
    private function sendAssessmentNotifications(Assessment $assessment, Patient $patient, $redFlag = null): array
    {
        $notifications = [];

        // 1. Notification to patient
        $notifications['patient'] = $this->notificationService->sendAssessmentCompleted(
            $patient,
            $assessment,
            $redFlag
        );

        // 2. If red flag exists, send to therapist
        if ($redFlag && $patient->therapist_id) {
            $notifications['therapist'] = $this->notificationService->sendRedFlagAlert(
                $patient->therapist_id,
                $assessment,
                $patient
            );
        }

        return [
            'sent' => count(array_filter($notifications)) > 0,
            'details' => $notifications
        ];
    }

    /**
     * Interpret the score based on assessment type
     * Used by: createAssessment() and formatAssessmentResponse() for result interpretation
     */
    private function interpretScore(Assessment $assessment): array
    {
        $type = $assessment->type;
        $score = $assessment->score;

        if ($type === 'phq9') {
            return $this->interpretPHQ9($score);
        } elseif ($type === 'gad7') {
            return $this->interpretGAD7($score);
        }

        return [
            'level' => 'unknown',
            'description' => 'Result interpretation not available'
        ];
    }

    /**
     * Interpret PHQ-9 score
     * Used by: interpretScore() for PHQ-9 assessments
     */
    private function interpretPHQ9(int $score): array
    {
        if ($score >= 20) return ['level' => 'severe', 'description' => 'Severe depression'];
        if ($score >= 15) return ['level' => 'moderately_severe', 'description' => 'Moderately severe depression'];
        if ($score >= 10) return ['level' => 'moderate', 'description' => 'Moderate depression'];
        if ($score >= 5) return ['level' => 'mild', 'description' => 'Mild depression'];
        return ['level' => 'minimal', 'description' => 'Minimal or no depression'];
    }

    /**
     * Interpret GAD-7 score
     * Used by: interpretScore() for GAD-7 assessments
     */
    private function interpretGAD7(int $score): array
    {
        if ($score >= 15) return ['level' => 'severe', 'description' => 'Severe anxiety'];
        if ($score >= 10) return ['level' => 'moderate', 'description' => 'Moderate anxiety'];
        if ($score >= 5) return ['level' => 'mild', 'description' => 'Mild anxiety'];
        return ['level' => 'minimal', 'description' => 'Minimal anxiety'];
    }

    /**
     * Generate recommendations based on assessment score
     * Used by: createAssessment() to provide actionable recommendations
     */
    private function getRecommendations(Assessment $assessment): array
    {
        $recommendations = [];

        if ($assessment->score >= 20) {
            $recommendations[] = [
                'priority' => 'high',
                'title' => 'Urgent Session',
                'description' => 'We recommend a session with a therapist within 24-48 hours',
                'action' => 'book_urgent_session'
            ];
        } elseif ($assessment->score >= 15) {
            $recommendations[] = [
                'priority' => 'medium',
                'title' => 'Regular Session',
                'description' => 'We recommend weekly sessions with a therapist',
                'action' => 'book_regular_session'
            ];
        } elseif ($assessment->score >= 10) {
            $recommendations[] = [
                'priority' => 'low',
                'title' => 'Self-care Follow-up',
                'description' => 'Continue educational modules and reassess after one week',
                'action' => 'continue_self_care'
            ];
        }

        // General recommendation
        $recommendations[] = [
            'priority' => 'info',
            'title' => 'Mood Tracking',
            'description' => 'Try to log your mood daily to track progress',
            'action' => 'log_mood_daily'
        ];

        return $recommendations;
    }

    /**
     * Format assessment data for API response
     * Used by: createAssessment() to structure the response
     */
    private function formatAssessmentResponse(Assessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'type' => $assessment->type,
            'score' => $assessment->score,
            'completed_at' => $assessment->completed_at->format('Y-m-d H:i'),
            'interpretation' => $this->interpretScore($assessment),
            'requires_attention' => $this->requiresRedFlag($assessment)
        ];
    }

    /**
     * Get statistics for patient's assessments
     * Used by: getAssessmentHistory() to provide overview statistics
     */
    private function getAssessmentStatistics(string $patientId): array
    {
        $phq9Avg = $this->assessmentRepository->getAverageScore($patientId, 'phq9');
        $gad7Avg = $this->assessmentRepository->getAverageScore($patientId, 'gad7');
        $latest = $this->assessmentRepository->getLatestByPatientId($patientId);

        return [
            'total_assessments' => $this->assessmentRepository->findByPatientId($patientId)->count(),
            'average_phq9' => round($phq9Avg, 1),
            'average_gad7' => round($gad7Avg, 1),
            'latest_score' => $latest ? $latest->score : null,
            'latest_type' => $latest ? $latest->type : null,
            'trend' => $this->calculateTrend($patientId)
        ];
    }

    /**
     * Calculate trend of assessments (improving/declining/stable)
     * Used by: getAssessmentStatistics() to show progress trend
     */
    private function calculateTrend(string $patientId): string
    {
        $assessments = $this->assessmentRepository->findByPatientId($patientId);

        if ($assessments->count() < 2) {
            return 'insufficient_data';
        }

        $recentScores = $assessments->take(3)->pluck('score')->toArray();

        if (count($recentScores) < 2) {
            return 'stable';
        }

        $first = $recentScores[0];
        $last = $recentScores[count($recentScores) - 1];

        if ($last < $first - 3) {
            return 'improving';
        } elseif ($last > $first + 3) {
            return 'declining';
        } else {
            return 'stable';
        }
    }

    /**
     * Check if user has permission to view encrypted answers
     * Used by: getDecryptedAnswer() for authorization check
     */
    private function hasPermissionToViewAnswers(User $user): bool
    {
        // Only therapists, clinical supervisors, and admins can view encrypted answers
        $allowedRoles = ['therapist', 'clinical_supervisor', 'admin', 'super_admin'];
        return in_array($user->role, $allowedRoles);
    }
}
