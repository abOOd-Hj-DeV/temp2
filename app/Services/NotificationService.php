<?php
// app/Services/NotificationService.php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Patient;
use App\Models\RedFlag;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * إرسال إشعار اكتمال التقييم
     */
    public function sendAssessmentCompleted(Patient $patient, Assessment $assessment, $redFlag = null): array
    {
        try {
            // 1. إعداد بيانات الإشعار
            $notificationData = [
                'patient_name' => $patient->full_name,
                'assessment_type' => strtoupper($assessment->type),
                'score' => $assessment->score,
                'date' => now()->format('Y-m-d H:i'),
                'has_red_flag' => !is_null($redFlag)
            ];

            // 2. إرسال إشعار داخل التطبيق (سيتم تطويره لاحقاً)
            $this->sendInAppNotification($patient->user_id, $notificationData);

            // 3. إذا كان هناك Red Flag، أرسل إشعار للمعالج
            if ($redFlag && $patient->therapist_id) {
                $this->sendTherapistAlert($patient->therapist_id, $assessment, $patient);
            }

            Log::info('Assessment notification sent', [
                'patient_id' => $patient->user_id,
                'assessment_id' => $assessment->id,
                'red_flag' => !is_null($redFlag)
            ]);

            return [
                'sent' => true,
                'notification_type' => 'assessment_completed',
                'red_flag_alert_sent' => !is_null($redFlag) && !is_null($patient->therapist_id)
            ];

        } catch (\Exception $e) {
            Log::error('Failed to send assessment notification', [
                'patient_id' => $patient->user_id,
                'error' => $e->getMessage()
            ]);

            return [
                'sent' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * إرسال إشعار Red Flag
     */
    public function sendRedFlagNotifications(RedFlag $redFlag, Assessment $assessment): array
    {
        try {
            $patient = Patient::find($redFlag->patient_id);

            if (!$patient) {
                throw new \Exception('Patient not found');
            }

            // 1. إشعار للمريض (تطمين)
            $patientNotification = $this->sendPatientRedFlagAlert($patient, $assessment);

            // 2. إشعار للمعالج إذا موجود
            $therapistNotification = null;
            if ($patient->therapist_id) {
                $therapistNotification = $this->sendTherapistRedFlagAlert(
                    $patient->therapist_id,
                    $redFlag,
                    $patient
                );
            }

            // 3. إشعار للمشرفين السريريين (للأسبوع التاسع)
            // $clinicalNotification = $this->notifyClinicalSupervisors($redFlag);

            Log::info('Red Flag notifications sent', [
                'red_flag_id' => $redFlag->id,
                'priority' => $redFlag->priority,
                'notifications_sent' => [
                    'patient' => $patientNotification['sent'],
                    'therapist' => $therapistNotification ? $therapistNotification['sent'] : false
                ]
            ]);

            return [
                'patient_notification' => $patientNotification,
                'therapist_notification' => $therapistNotification,
                'clinical_notification' => 'pending' // سيتم في الأسبوع التاسع
            ];

        } catch (\Exception $e) {
            Log::error('Failed to send Red Flag notifications', [
                'red_flag_id' => $redFlag->id,
                'error' => $e->getMessage()
            ]);

            return [
                'error' => $e->getMessage(),
                'sent' => false
            ];
        }
    }

    /**
     * إرسال إشعار للمريض بوجود Red Flag
     */
    private function sendPatientRedFlagAlert(Patient $patient, Assessment $assessment): array
    {
        // TODO: سيتم تطوير هذا لاحقاً مع Reverb
        return [
            'sent' => true,
            'type' => 'patient_alert',
            'message' => 'Your assessment has been reviewed. A therapist will contact you shortly.'
        ];
    }

    /**
     * إرسال إشعار للمعالج عن Red Flag
     */
    private function sendTherapistRedFlagAlert(string $therapistId, RedFlag $redFlag, Patient $patient): array
    {
        // TODO: سيتم تطوير هذا لاحقاً
        return [
            'sent' => true,
            'type' => 'therapist_alert',
            'message' => "Patient {$patient->full_name} requires immediate attention. Score: {$redFlag->assessment->score}",
            'priority' => $redFlag->priority
        ];
    }

    /**
     * إرسال إشعار داخل التطبيق
     */
    private function sendInAppNotification(string $userId, array $data): void
    {
        // TODO: سيتم تطوير هذا مع Laravel Reverb
        Log::info('In-app notification queued', [
            'user_id' => $userId,
            'data' => $data
        ]);
    }

    /**
     * إرسال إشعار للمعالج (للتحقق)
     */
    private function sendTherapistAlert(string $therapistId, Assessment $assessment, Patient $patient): void
    {
        // TODO: سيتم تطوير هذا لاحقاً
        Log::info('Therapist alert queued', [
            'therapist_id' => $therapistId,
            'patient_id' => $patient->user_id,
            'assessment_id' => $assessment->id,
            'score' => $assessment->score
        ]);
    }
}
