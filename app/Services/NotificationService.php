<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentReviewStatus;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Jobs\RetryNotificationJob;
use App\Models\Assessment;
use App\Models\DocumentRequest;
use App\Models\Message;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\RedFlag;
use App\Models\SessionRecommendation;
use App\Models\SupportReply;
use App\Models\Therapist;
use App\Models\TherapistSwitch;
use App\Models\TherapySession;
use App\Models\User;
use App\Notifications\AssessmentCompletedNotification;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\DocumentRequestNotification;
use App\Notifications\PaymentProofPendingNotification;
use App\Notifications\PaymentReviewedNotification;
use App\Notifications\PaymentReviewOverdueNotification;
use App\Notifications\RedFlagEscalatedNotification;
use App\Notifications\RedFlagRaisedNotification;
use App\Notifications\SessionBookedNotification;
use App\Notifications\SessionRecommendationNotification;
use App\Notifications\SessionReminderNotification;
use App\Notifications\SessionStatusChangedNotification;
use App\Notifications\SupportReplyNotification;
use App\Notifications\TherapistApprovalNotification;
use App\Notifications\TherapistSwitchDecidedNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationOutboxService;
use App\Support\SessionClock;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Decides who is told what for each domain event: in-app (database)
 * notifications for every party, plus WhatsApp alerts for critical clinical
 * and payment events. Channel delivery and the delivery ledger live in
 * NotificationDispatcher.
 *
 * Delivery is idempotent independently per channel and recipient.
 */
class NotificationService
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    /**
     * Stage delivery in the current transaction, then attempt it after commit.
     * Delivery failures remain retryable; inability to persist work propagates.
     */
    public function deliver(string $method, mixed ...$args): void
    {
        if (! method_exists($this, $method) || in_array($method, ['deliver', 'notifyOnce', '__construct'], true)) {
            throw new \InvalidArgumentException('Unknown notification event.');
        }

        app(NotificationOutboxService::class)->stage(new RetryNotificationJob($method, $args), inline: true);
    }

    public function assessmentCompleted(Patient $patient, Assessment $assessment): void
    {
        $this->notifyOnce($patient->user, new AssessmentCompletedNotification($assessment), "assessment.completed:{$assessment->id}");
    }

    /**
     * Alert the flag's owner (clinical supervisor by default) and the
     * patient's current therapist. Each recipient is reached once per flag.
     */
    public function redFlagRaised(RedFlag $redFlag): void
    {
        $recipients = collect([$redFlag->assignedUser, $this->currentTherapistFor($redFlag)])
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id');

        if ($recipients->isEmpty()) {
            Log::warning('Red flag raised with no assignee to notify', ['red_flag_id' => $redFlag->id]);

            return;
        }

        $key = "red_flag.raised:{$redFlag->id}";

        foreach ($recipients as $recipient) {
            $this->notifyOnce($recipient, new RedFlagRaisedNotification($redFlag), $key);

            // Message carries no patient identity: it goes through a third-party provider.
            $this->dispatcher->whatsApp(
                $recipient,
                sprintf(
                    'Sakina alert: a %s red flag (%s priority) is awaiting your review. Ref %s.',
                    $redFlag->type->value,
                    $redFlag->priority->value,
                    substr($redFlag->id, 0, 8)
                ),
                $key,
                ['red_flag_id' => $redFlag->id]
            );
        }
    }

    /**
     * A merged signal raised an open flag's priority: the same recipients are
     * told again, once per priority level reached.
     */
    public function redFlagPriorityRaised(RedFlag $redFlag): void
    {
        $recipients = collect([$redFlag->assignedUser, $this->currentTherapistFor($redFlag)])
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id');

        $key = "red_flag.priority_raised:{$redFlag->id}:{$redFlag->priority->value}";

        foreach ($recipients as $recipient) {
            $this->notifyOnce($recipient, new RedFlagRaisedNotification($redFlag, 'red_flag_priority_raised'), $key);

            $this->dispatcher->whatsApp(
                $recipient,
                sprintf(
                    'Sakina alert: red flag ref %s was raised to %s priority and needs your review.',
                    substr($redFlag->id, 0, 8),
                    $redFlag->priority->value
                ),
                $key,
                ['red_flag_id' => $redFlag->id]
            );
        }
    }

    /**
     * The patient's assigned therapist, only while active and approved.
     */
    private function currentTherapistFor(RedFlag $redFlag): ?User
    {
        $therapistId = Patient::whereKey($redFlag->patient_id)->value('therapist_id');

        if ($therapistId === null) {
            return null;
        }

        return User::whereKey($therapistId)
            ->where('is_active', true)
            ->where('role', UserRole::THERAPIST->value)
            ->whereHas('therapist', fn ($q) => $q->where('approval_status', ApprovalStatus::APPROVED->value))
            ->first();
    }

    /**
     * Alert every active clinical staff member that a flag has gone unhandled.
     * Returns how many recipients were actually reached for the first time;
     * a retried escalation only reaches staff the earlier attempt missed.
     *
     * @param  iterable<User>  $staff
     */
    public function redFlagEscalated(RedFlag $redFlag, iterable $staff): int
    {
        $delivered = 0;
        $key = "red_flag.escalated:{$redFlag->id}";

        foreach ($staff as $user) {
            if ($this->notifyOnce($user, new RedFlagEscalatedNotification($redFlag), $key)) {
                $delivered++;
            }

            $this->dispatcher->whatsApp(
                $user,
                sprintf(
                    'Sakina ESCALATION: %s red flag (%s priority) ref %s is still open and unhandled. Immediate review required.',
                    $redFlag->type->value,
                    $redFlag->priority->value,
                    substr($redFlag->id, 0, 8)
                ),
                $key,
                ['red_flag_id' => $redFlag->id, 'escalated_to' => $user->id]
            );
        }

        return $delivered;
    }

    public function sessionBooked(TherapySession $session): void
    {
        $key = "session.booked:{$session->id}";

        $this->notifyOnce($session->patient?->user, new SessionBookedNotification($session), $key);
        $this->notifyOnce($session->therapist?->user, new SessionBookedNotification($session), $key);

        $this->dispatcher->whatsApp(
            $session->patient?->user,
            sprintf(
                'Sakina: your session on %s was booked%s.',
                $this->localSessionTime($session, $session->patient?->user),
                $session->is_initial ? ' (initial session, free of charge)' : ''
            ),
            $key,
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

    public function sessionCancelRequested(TherapySession $session, User $recipient): void
    {
        $key = "session.cancel_requested:{$session->id}:{$session->cancel_requested_at?->timestamp}";

        $this->notifyOnce($recipient, new SessionStatusChangedNotification($session, $session->status, $session->status, 'cancellation_requested'), $key);
    }

    public function sessionCancelDecided(TherapySession $session, bool $approved): void
    {
        $key = "session.cancel_decided:{$session->id}:{$session->updated_at?->timestamp}";

        $this->notifyOnce(
            $session->patient?->user,
            new SessionStatusChangedNotification($session, $session->status, $session->status, $approved ? 'cancellation_approved' : 'cancellation_rejected'),
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
        $key = "payment.review_overdue:{$payment->id}";

        foreach ($reviewers as $reviewer) {
            $this->notifyOnce($reviewer, new PaymentReviewOverdueNotification($payment, $pendingHours), $key);

            $this->dispatcher->whatsApp(
                $reviewer,
                sprintf(
                    'Sakina: a payment proof (ref %s) has been awaiting review for %d hours. Please review it.',
                    substr($payment->id, 0, 8),
                    $pendingHours
                ),
                $key,
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

        $key = "payment.reviewed:{$payment->id}:{$payment->status?->value}";

        $this->notifyOnce($patientUser, new PaymentReviewedNotification($payment), $key);

        if ($payment->status === PaymentReviewStatus::APPROVED) {
            $this->dispatcher->whatsApp(
                $patientUser,
                'Sakina: your payment was approved. Thank you.',
                $key,
                ['payment_id' => $payment->id]
            );
        }
    }

    public function therapistApprovalDecided(Therapist $therapist, ?string $reason = null): void
    {
        $status = $therapist->approval_status?->value;
        $key = "therapist.approval:{$therapist->user_id}:{$status}:{$therapist->updated_at?->timestamp}";

        $this->notifyOnce($therapist->user, new TherapistApprovalNotification($therapist, $reason), $key);

        if ($status === 'approved') {
            $this->dispatcher->whatsApp(
                $therapist->user,
                'Sakina: your therapist profile was approved. You can now receive clients.',
                $key,
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
            $this->notifyOnce($switch->oldTherapist?->user, new TherapistSwitchDecidedNotification($switch, 'patient_transferred'), $key);
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

    /** Staff asked a user for a document. */
    public function documentRequested(DocumentRequest $request): void
    {
        $this->notifyOnce($request->user, new DocumentRequestNotification($request, 'document_requested'), "document_request.requested:{$request->id}");
    }

    /** The user uploaded the document; the requesting staff member is told. */
    public function documentSubmitted(DocumentRequest $request): void
    {
        $key = "document_request.submitted:{$request->id}:".($request->submitted_at?->timestamp ?? 0);

        $this->notifyOnce($request->requester, new DocumentRequestNotification($request, 'document_submitted'), $key);
    }

    /** Staff approved or rejected the document. */
    public function documentReviewed(DocumentRequest $request): void
    {
        $key = "document_request.reviewed:{$request->id}:{$request->status}:".($request->reviewed_at?->timestamp ?? 0);

        $this->notifyOnce($request->user, new DocumentRequestNotification($request, 'document_reviewed'), $key);
    }

    /**
     * The chat layer calls this only for the first unread message of a burst,
     * so a receiver gets one alert per unread run rather than one per message.
     */
    public function chatMessageReceived(Message $message): void
    {
        $this->notifyOnce($message->receiver, new ChatMessageReceivedNotification($message), "chat.message:{$message->id}");
    }

    /**
     * @param  string  $window  '24h' | '1h'
     */
    public function sessionReminder(TherapySession $session, string $window): void
    {
        $scheduleKey = hash('sha256', SessionClock::fromStored($session->session_date, (string) $session->session_time)->startOfMinute()->toIso8601String());
        $key = "session.reminder:{$session->id}:{$scheduleKey}:{$window}";

        $this->notifyOnce($session->patient?->user, new SessionReminderNotification($session, $window), $key);
        $this->notifyOnce($session->therapist?->user, new SessionReminderNotification($session, $window), $key);

        $this->dispatcher->whatsApp(
            $session->patient?->user,
            sprintf('Sakina reminder: your session is on %s.', $this->localSessionTime($session, $session->patient?->user)),
            $key,
            ['session_id' => $session->id, 'window' => $window, 'schedule_key' => $scheduleKey]
        );
    }

    public function sessionRecommendationSaved(SessionRecommendation $recommendation): void
    {
        $this->notifyOnce(
            User::find($recommendation->patient_id),
            new SessionRecommendationNotification($recommendation),
            "session_recommendation:{$recommendation->id}:v{$recommendation->revision}"
        );
    }

    /**
     * A staff reply reaches the ticket owner; a patient reply reaches the
     * assigned agent (nobody is paged when the ticket is unassigned — it is
     * visible in the open-tickets queue).
     */
    public function supportReplied(SupportReply $reply): void
    {
        $ticket = $reply->ticket;

        if ($ticket === null) {
            return;
        }

        $recipientId = $reply->is_staff ? $ticket->user_id : $ticket->assigned_to;

        if ($recipientId === null || $recipientId === $reply->user_id) {
            return;
        }

        $this->notifyOnce(User::find($recipientId), new SupportReplyNotification($reply), "support_reply:{$reply->id}");
    }

    /**
     * Store the database notification exactly once per (event, recipient).
     * Returns true only when this call created it.
     */
    public function notifyOnce(?User $user, Notification $notification, string $eventKey): bool
    {
        return $this->dispatcher->inApp($user, $notification, $eventKey);
    }

    /** "YYYY-MM-DD at HH:MM (Zone)" in the recipient's timezone. */
    private function localSessionTime(TherapySession $session, ?User $recipient): string
    {
        if ($session->session_date === null || $session->session_time === null) {
            return 'the scheduled time';
        }

        $local = SessionClock::localize(
            SessionClock::fromStored($session->session_date, (string) $session->session_time),
            $recipient?->timezone() ?? SessionClock::UTC
        );

        return sprintf('%s at %s (%s)', $local['date'], $local['time'], $local['timezone']);
    }
}
