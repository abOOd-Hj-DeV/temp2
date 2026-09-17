<?php

namespace App\Notifications;

use App\Enums\SessionStatus;
use App\Models\TherapySession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SessionStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private TherapySession $session,
        private SessionStatus $from,
        private SessionStatus $to,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'session_status_changed',
            'session_id' => $this->session->id,
            'from_status' => $this->from->value,
            'to_status' => $this->to->value,
            'session_date' => $this->session->session_date?->toDateString(),
        ];
    }
}
