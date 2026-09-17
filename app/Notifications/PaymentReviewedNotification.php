<?php

namespace App\Notifications;

use App\Enums\PaymentReviewStatus;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PaymentReviewedNotification extends Notification
{
    use Queueable;

    public function __construct(private Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'payment_reviewed',
            'payment_id' => $this->payment->id,
            'status' => $this->payment->status?->value ?? PaymentReviewStatus::PENDING->value,
            'subscription_id' => $this->payment->subscription_id,
            'therapy_session_id' => $this->payment->therapy_session_id,
            'amount' => $this->payment->amount,
            'note' => $this->payment->note,
        ];
    }
}
