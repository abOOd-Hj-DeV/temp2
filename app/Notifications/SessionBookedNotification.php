<?php

namespace App\Notifications;

use App\Models\TherapySession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SessionBookedNotification extends Notification
{
    use Queueable;

    public function __construct(private TherapySession $session) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'session_booked',
            'session_id' => $this->session->id,
            'patient_id' => $this->session->patient_id,
            'therapist_id' => $this->session->therapist_id,
            'session_date' => $this->session->session_date?->toDateString(),
            'session_time' => $this->session->session_time,
            'medium' => $this->session->medium?->value,
            'price' => $this->session->price,
            'is_initial' => $this->session->is_initial,
        ];
    }
}
