<?php

namespace App\Notifications;

use App\Models\RedFlag;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RedFlagRaisedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private RedFlag $redFlag,
        private string $kind = 'red_flag_raised',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'red_flag_id' => $this->redFlag->id,
            'patient_id' => $this->redFlag->patient_id,
            'type' => $this->redFlag->type,
            'priority' => $this->redFlag->priority,
        ];
    }
}
