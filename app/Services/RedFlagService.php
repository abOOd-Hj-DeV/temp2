<?php
// app/Services/RedFlagService.php

namespace App\Services;

use App\Models\Assessment;
use App\Enums\RedFlagType;
use App\Enums\RedFlagPriority;
use App\Repositories\Contracts\RedFlagRepositoryInterface;
use Illuminate\Support\Facades\Log;

class RedFlagService
{
    public function __construct(
        private RedFlagRepositoryInterface $redFlagRepository,
        private NotificationService $notificationService
    ) {}

    /**
     * إنشاء Red Flag من تقييم حرج
     */
    public function createFromAssessment(Assessment $assessment): ?\App\Models\RedFlag
    {
        try {
            // 1. إنشاء Red Flag باستخدام الـ Repository
            $redFlag = $this->redFlagRepository->create([
                'patient_id' => $assessment->patient_id,
                'type' => $this->getRedFlagType($assessment->type),
                'description' => $this->generateDescription($assessment),
                'priority' => $this->determinePriority($assessment->score),
                'assessment_id' => $assessment->id,
                'status' => 'open'
            ]);

            // 2. إرسال الإشعارات
            $this->notificationService->sendRedFlagNotifications($redFlag, $assessment);

            Log::info('Red Flag created successfully', [
                'assessment_id' => $assessment->id,
                'red_flag_id' => $redFlag->id,
                'score' => $assessment->score
            ]);

            return $redFlag;

        } catch (\Exception $e) {
            Log::error('Failed to create Red Flag', [
                'assessment_id' => $assessment->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * الحصول على Red Flags لمريض معين
     */
    public function getPatientRedFlags(string $patientId): array
    {
        $redFlags = $this->redFlagRepository->findByPatientId($patientId);

        return $redFlags->map(function ($redFlag) {
            return [
                'id' => $redFlag->id,
                'type' => $redFlag->type,
                'description' => $redFlag->description,
                'priority' => $redFlag->priority,
                'status' => $redFlag->status,
                'created_at' => $redFlag->created_at->format('Y-m-d H:i'),
                'action_taken' => $redFlag->action_taken
            ];
        })->toArray();
    }

    /**
     * الحصول على Red Flags المفتوحة
     */
    public function getOpenRedFlags(array $filters = []): array
    {
        $redFlags = $this->redFlagRepository->getOpenRedFlags($filters);

        return $redFlags->map(function ($redFlag) {
            return $this->formatRedFlagResponse($redFlag);
        })->toArray();
    }

    /**
     * تحديث حالة Red Flag
     */
    public function updateRedFlagStatus(string $redFlagId, string $status, ?string $actionTaken = null): bool
    {
        return $this->redFlagRepository->updateStatus($redFlagId, $status, $actionTaken);
    }

    /**
     * تعيين Red Flag لمستخدم
     */
    public function assignRedFlag(string $redFlagId, string $userId): bool
    {
        return $this->redFlagRepository->assignTo($redFlagId, $userId);
    }

    /**
     * الحصول على إحصائيات Red Flags
     */
    public function getRedFlagStats(array $filters = []): array
    {
        return $this->redFlagRepository->getStats($filters);
    }

    /**
     * تنسيق استجابة Red Flag
     */
    private function formatRedFlagResponse(\App\Models\RedFlag $redFlag): array
    {
        return [
            'id' => $redFlag->id,
            'patient' => $redFlag->patient ? [
                'id' => $redFlag->patient->user_id,
                'name' => $redFlag->patient->full_name
            ] : null,
            'type' => $redFlag->type,
            'description' => $redFlag->description,
            'priority' => $redFlag->priority,
            'status' => $redFlag->status,
            'created_at' => $redFlag->created_at->format('Y-m-d H:i:s'),
            'action_taken' => $redFlag->action_taken,
            'assigned_to' => $redFlag->assigned_to,
            'assessment' => $redFlag->assessment ? [
                'id' => $redFlag->assessment->id,
                'type' => $redFlag->assessment->type,
                'score' => $redFlag->assessment->score,
                'date' => $redFlag->assessment->completed_at->format('Y-m-d H:i:s')
            ] : null
        ];
    }

    // ========== الدوال المساعدة ==========

    /**
     * تحديد نوع Red Flag بناءً على نوع التقييم
     */
    private function getRedFlagType(string $assessmentType): string
    {
        return match($assessmentType) {
            'phq9' => RedFlagType::LOW_MOOD->value,
            'gad7' => RedFlagType::LOW_MOOD->value,
            default => RedFlagType::SAFETY->value
        };
    }

    /**
     * تحديد أولوية Red Flag بناءً على النتيجة
     */
    private function determinePriority(int $score): string
    {
        if ($score >= 20) {
            return RedFlagPriority::HIGH->value;
        } elseif ($score >= 15) {
            return RedFlagPriority::MEDIUM->value;
        } else {
            return RedFlagPriority::LOW->value;
        }
    }

    /**
     * توليد وصف Red Flag
     */
    private function generateDescription(Assessment $assessment): string
    {
        $type = $assessment->type === 'phq9' ? 'PHQ-9' : 'GAD-7';
        $severity = $this->getSeverityText($assessment->score);

        return "Patient scored {$assessment->score} on {$type} assessment ({$severity} severity). Requires immediate attention.";
    }

    /**
     * الحصول على نص مستوى الخطورة
     */
    private function getSeverityText(int $score): string
    {
        if ($score >= 20) return 'severe';
        if ($score >= 15) return 'moderate';
        return 'mild';
    }
}
