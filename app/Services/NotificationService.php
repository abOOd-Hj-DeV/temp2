<?php

namespace App\Services;

use App\Enums\PaymentReviewStatus;
use App\Enums\SessionStatus;
use App\Models\Assessment;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\RedFlag;
use App\Models\Therapist;
use App\Models\TherapistSwitch;
use App\Models\TherapySession;
use App\Models\User;
use App\Notifications\AssessmentCompletedNotification;
use App\Notifications\PaymentProofPendingNotification;
use App\Notifications\PaymentReviewedNotification;
use App\Notifications\RedFlagRaisedNotification;
use App\Notifications\SessionBookedNotification;
use App\Notifications\SessionReminderNotification;
use App\Notifications\SessionStatusChangedNotification;
use App\Notifications\TherapistApprovalNotification;
use App\Notifications\TherapistSwitchDecidedNotification;
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

    public function sessionBooked(TherapySession $session): void
    {
        $notification = new SessionBookedNotification($session);

        $session->patient?->user?->notify($notification);
        $session->therapist?->user?->notify($notification);

        $this->sendWhatsAppSafe(
            $session->patient?->user?->whatsapp_number,
            sprintf(
                'Sakina: your session on %s at %s was booked%s.',
                $session->session_date?->toDateString(),
                substr((string) $session->session_time, 0, 5),
                $session->is_initial ? ' (initial session, free of charge)' : ''
            ),
            ['session_id' => $session->id]
        );
    }

    public function sessionStatusChanged(TherapySession $session, SessionStatus $from, SessionStatus $to): void
    {
        $notification = new SessionStatusChangedNotification($session, $from, $to);

        $session->patient?->user?->notify($notification);
        $session->therapist?->user?->notify($notification);
    }

    public function paymentProofSubmitted(Payment $payment, iterable $reviewers): void
    {
        foreach ($reviewers as $reviewer) {
            $reviewer->notify(new PaymentProofPendingNotification($payment));
        }
    }

    public function paymentReviewed(Payment $payment): void
    {
        $patientUser = $payment->subscription?->patient?->user
            ?? $payment->session?->patient?->user;

        if (! $patientUser instanceof User) {
            return;
        }

        $patientUser->notify(new PaymentReviewedNotification($payment));

        if ($payment->status === PaymentReviewStatus::APPROVED) {
            $this->sendWhatsAppSafe(
                $patientUser->whatsapp_number,
                'Sakina: your payment was approved. Thank you.',
                ['payment_id' => $payment->id]
            );
        }
    }

    public function therapistApprovalDecided(Therapist $therapist): void
    {
        $therapist->user?->notify(new TherapistApprovalNotification($therapist));

        if ($therapist->approval_status?->value === 'approved') {
            $this->sendWhatsAppSafe(
                $therapist->user?->whatsapp_number,
                'Sakina: your therapist profile was approved. You can now receive clients.',
                ['therapist_id' => $therapist->user_id]
            );
        }
    }

    public function therapistSwitchDecided(TherapistSwitch $switch): void
    {
        $switch->patient?->user?->notify(new TherapistSwitchDecidedNotification($switch));

        if ($switch->status === 'approved') {
            $switch->newTherapist?->user?->notify(new TherapistSwitchDecidedNotification($switch));
        }
    }

    /**
     * @param  string  $window  '24h' | '1h'
     */
    public function sessionReminder(TherapySession $session, string $window): void
    {
        $notification = new SessionReminderNotification($session, $window);

        $session->patient?->user?->notify($notification);
        $session->therapist?->user?->notify($notification);

        $this->sendWhatsAppSafe(
            $session->patient?->user?->whatsapp_number,
            sprintf('Sakina reminder: your session is on %s at %s.', $session->session_date?->toDateString(), substr((string) $session->session_time, 0, 5)),
            ['session_id' => $session->id, 'window' => $window]
        );
    }

    private function sendWhatsAppSafe(?string $number, string $message, array $context = []): void
    {
        if (empty($number)) {
            return;
        }

        try {
            $this->whatsApp->send($number, $message);
        } catch (\Throwable $e) {
            Log::error('WhatsApp notification failed', $context + ['error' => $e->getMessage()]);
        }
    }
}
