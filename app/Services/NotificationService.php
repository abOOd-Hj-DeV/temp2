<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Patient;
use App\Models\RedFlag;
use App\Models\User;
use App\Notifications\AssessmentCompletedNotification;
use App\Notifications\RedFlagRaisedNotification;
use App\Services\Messaging\WhatsAppSenderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches in-app (database) notifications and, for critical
 * clinical events, WhatsApp alerts to the responsible staff member.
 */
class NotificationService
{
    public function __construct(
        private WhatsAppSenderInterface $whatsApp,
    ) {}

    public function assessmentCompleted(Patient $patient, Assessment $assessment): void
    {
        $patient->user?->notify(new AssessmentCompletedNotification($assessment));
    }

    public function redFlagRaised(RedFlag $redFlag): void
    {
        $assignee = $redFlag->assignedUser;

        if (! $assignee instanceof User) {
            Log::warning('Red flag raised with no assignee to notify', [
                'red_flag_id' => $redFlag->id,
            ]);

            return;
        }

        $assignee->notify(new RedFlagRaisedNotification($redFlag));

        try {
            $this->whatsApp->send(
                $assignee->whatsapp_number,
                sprintf(
                    'Sakina alert: a %s red flag (%s priority) was raised for patient %s. Please review.',
                    $redFlag->type->value,
                    $redFlag->priority->value,
                    $redFlag->patient?->full_name ?? $redFlag->patient_id
                )
            );
        } catch (\Throwable $e) {
            // WhatsApp delivery must never block clinical record creation.
            Log::error('Red flag WhatsApp alert failed', [
                'red_flag_id' => $redFlag->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
