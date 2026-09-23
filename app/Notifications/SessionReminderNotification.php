<?php

namespace App\Notifications;

use App\Models\TherapySession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SessionReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private TherapySession $session, private string $window) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'session_reminder',
            'window' => $this->window,
            'session_id' => $this->session->id,
            'session_date' => $this->session->session_date?->toDateString(),
            'session_time' => $this->session->session_time,
        ];
    }
}
