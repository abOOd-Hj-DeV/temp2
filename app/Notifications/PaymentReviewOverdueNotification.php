<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PaymentReviewOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(private Payment $payment, private int $pendingHours) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'payment_review_overdue',
            'payment_id' => $this->payment->id,
            'subscription_id' => $this->payment->subscription_id,
            'therapy_session_id' => $this->payment->therapy_session_id,
            'amount' => $this->payment->amount,
            'pending_hours' => $this->pendingHours,
        ];
    }
}
