<?php

namespace App\Services;

use App\Enums\PaymentReviewStatus;
use App\Enums\SessionStatus;
use App\Jobs\SendWhatsAppMessageJob;
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
use App\Notifications\PaymentReviewOverdueNotification;
use App\Notifications\RedFlagEscalatedNotification;
use App\Notifications\RedFlagRaisedNotification;
use App\Notifications\SessionBookedNotification;
use App\Notifications\SessionReminderNotification;
use App\Notifications\SessionStatusChangedNotification;
use App\Notifications\TherapistApprovalNotification;
use App\Notifications\TherapistSwitchDecidedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Dispatches in-app (database) notifications and, for critical clinical
 * events, WhatsApp alerts to the responsible staff member.
 *
 * Delivery is idempotent per (event, recipient): the database notification
 * id is derived deterministically from the event key, so a replayed job or
 * a retried request cannot produce a second copy, and the WhatsApp message
 * is only queued the first time the event is recorded.
 */
class NotificationService
{
    private const NAMESPACE = '5f5b1e7a-8c3b-4e0d-9a3c-2f1d0b6c7e11';

    public function assessmentCompleted(Patient $patient, Assessment $assessment): void
    {
        $this->notifyOnce($patient->user, new AssessmentCompletedNotification($assessment), "assessment.completed:{$assessment->id}");
    }

    public function redFlagRaised(RedFlag $redFlag): void
    {
        $assignee = $redFlag->assignedUser;

        if (! $assignee instanceof User) {
            Log::warning('Red flag raised with no assignee to notify', ['red_flag_id' => $redFlag->id]);

            return;
        }

        if (! $this->notifyOnce($assignee, new RedFlagRaisedNotification($redFlag), "red_flag.raised:{$redFlag->id}")) {
            return;
        }

        // Message carries no patient identity: it goes through a third-party provider.
        $this->sendWhatsAppSafe(
            $assignee->whatsapp_number,
            sprintf(
                'Sakina alert: a %s red flag (%s priority) is awaiting your review. Ref %s.',
                $redFlag->type->value,
                $redFlag->priority->value,
                substr($redFlag->id, 0, 8)
            ),
            ['red_flag_id' => $redFlag->id]
        );
    }

    /**
     * Alert every active clinical staff member that a flag has gone unhandled.
     * Returns how many recipients were actually reached for the first time.
     *
     * @param  iterable<User>  $staff
     */
    public function redFlagEscalated(RedFlag $redFlag, iterable $staff, int $attempt = 1): int
    {
        $delivered = 0;

        foreach ($staff as $user) {
            if (! $this->notifyOnce($user, new RedFlagEscalatedNotification($redFlag), "red_flag.escalated:{$redFlag->id}:{$attempt}")) {
                continue;
            }

            $delivered++;

            $this->sendWhatsAppSafe(
                $user->whatsapp_number,
                sprintf(
                    'Sakina ESCALATION: %s red flag (%s priority) ref %s is still open and unhandled. Immediate review required.',
                    $redFlag->type->value,
                    $redFlag->priority->value,
                    substr($redFlag->id, 0, 8)
                ),
                ['red_flag_id' => $redFlag->id, 'escalated_to' => $user->id]
            );
        }

        return $delivered;
    }

    public function sessionBooked(TherapySession $session): void
    {
        $key = "session.booked:{$session->id}";

        $first = $this->notifyOnce($session->patient?->user, new SessionBookedNotification($session), $key);
        $this->notifyOnce($session->therapist?->user, new SessionBookedNotification($session), $key);

        if (! $first) {
            return;
        }

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
        $key = "session.status:{$session->id}:{$from->value}:{$to->value}";

        $this->notifyOnce($session->patient?->user, new SessionStatusChangedNotification($session, $from, $to), $key);
        $this->notifyOnce($session->therapist?->user, new SessionStatusChangedNotification($session, $from, $to), $key);
    }

    public function sessionRescheduleRequested(TherapySession $session, User $recipient): void
    {
        $key = "session.reschedule_requested:{$session->id}:{$session->reschedule_requested_at?->timestamp}";

        $this->notifyOnce($recipient, new SessionStatusChangedNotification($session, $session->status, $session->status, 'reschedule_requested'), $key);
    }

    public function sessionRescheduleDecided(TherapySession $session, bool $approved): void
    {
        $key = "session.reschedule_decided:{$session->id}:{$session->updated_at?->timestamp}";

        $this->notifyOnce(
            $session->patient?->user,
            new SessionStatusChangedNotification($session, $session->status, $session->status, $approved ? 'reschedule_approved' : 'reschedule_rejected'),
            $key
        );
    }

    public function paymentProofSubmitted(Payment $payment, iterable $reviewers): void
    {
        foreach ($reviewers as $reviewer) {
            $this->notifyOnce($reviewer, new PaymentProofPendingNotification($payment), "payment.proof_pending:{$payment->id}");
        }
    }

    public function paymentReviewOverdue(Payment $payment, iterable $reviewers, int $pendingHours): void
    {
        foreach ($reviewers as $reviewer) {
            if (! $this->notifyOnce($reviewer, new PaymentReviewOverdueNotification($payment, $pendingHours), "payment.review_overdue:{$payment->id}")) {
                continue;
            }

            $this->sendWhatsAppSafe(
                $reviewer->whatsapp_number,
                sprintf(
                    'Sakina: a payment proof (ref %s) has been awaiting review for %d hours. Please review it.',
                    substr($payment->id, 0, 8),
                    $pendingHours
                ),
                ['payment_id' => $payment->id, 'reviewer_id' => $reviewer->id]
            );
        }
    }

    public function paymentReviewed(Payment $payment): void
    {
        $patientUser = $payment->subscription?->patient?->user
            ?? $payment->session?->patient?->user;

        if (! $patientUser instanceof User) {
            return;
        }

        if (! $this->notifyOnce($patientUser, new PaymentReviewedNotification($payment), "payment.reviewed:{$payment->id}:{$payment->status?->value}")) {
            return;
        }

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
        $status = $therapist->approval_status?->value;
        $key = "therapist.approval:{$therapist->user_id}:{$status}:{$therapist->updated_at?->timestamp}";

        if (! $this->notifyOnce($therapist->user, new TherapistApprovalNotification($therapist), $key)) {
            return;
        }

        if ($status === 'approved') {
            $this->sendWhatsAppSafe(
                $therapist->user?->whatsapp_number,
                'Sakina: your therapist profile was approved. You can now receive clients.',
                ['therapist_id' => $therapist->user_id]
            );
        }
    }

    public function therapistSwitchDecided(TherapistSwitch $switch): void
    {
        $key = "therapist_switch.decided:{$switch->id}:{$switch->status}";

        $this->notifyOnce($switch->patient?->user, new TherapistSwitchDecidedNotification($switch), $key);

        if ($switch->status === 'approved') {
            $this->notifyOnce($switch->newTherapist?->user, new TherapistSwitchDecidedNotification($switch), $key);
        }
    }

    /** Target therapist is asked to accept or decline a patient's switch request. */
    public function therapistSwitchRequested(TherapistSwitch $switch): void
    {
        $this->notifyOnce($switch->newTherapist?->user, new TherapistSwitchDecidedNotification($switch), "therapist_switch.requested:{$switch->id}");
    }

    /** Supervisors are asked for the final decision once the target therapist accepted. */
    public function therapistSwitchAwaitingSupervisor(TherapistSwitch $switch, iterable $supervisors): void
    {
        foreach ($supervisors as $supervisor) {
            $this->notifyOnce($supervisor, new TherapistSwitchDecidedNotification($switch), "therapist_switch.awaiting_supervisor:{$switch->id}");
        }
    }

    /**
     * @param  string  $window  '24h' | '1h'
     */
    public function sessionReminder(TherapySession $session, string $window): void
    {
        $key = "session.reminder:{$session->id}:{$window}";

        $first = $this->notifyOnce($session->patient?->user, new SessionReminderNotification($session, $window), $key);
        $this->notifyOnce($session->therapist?->user, new SessionReminderNotification($session, $window), $key);

        if (! $first) {
            return;
        }

        $this->sendWhatsAppSafe(
            $session->patient?->user?->whatsapp_number,
            sprintf('Sakina reminder: your session is on %s at %s.', $session->session_date?->toDateString(), substr((string) $session->session_time, 0, 5)),
            ['session_id' => $session->id, 'window' => $window]
        );
    }

    /**
     * Store the database notification exactly once per (event, recipient).
     * Returns true only when this call created it.
     */
    public function notifyOnce(?User $user, Notification $notification, string $eventKey): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $id = Uuid::uuid5(self::NAMESPACE, "{$eventKey}|{$user->id}")->toString();

        if (DatabaseNotification::whereKey($id)->exists()) {
            return false;
        }

        $notification->id = $id;

        try {
            DB::transaction(fn () => $user->notify($notification));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Queue a WhatsApp message (retried with backoff by the job). Enqueueing
     * failures are logged so notification problems never abort the caller.
     */
    private function sendWhatsAppSafe(?string $number, string $message, array $context = []): void
    {
        if (empty($number)) {
            return;
        }

        try {
            SendWhatsAppMessageJob::dispatch($number, $message, $context);
        } catch (\Throwable $e) {
            Log::error('WhatsApp notification failed', $context + ['error' => $e->getMessage()]);
        }
    }
}
