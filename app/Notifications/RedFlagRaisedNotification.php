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
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'red_flag_raised',
            'red_flag_id' => $this->redFlag->id,
            'patient_id' => $this->redFlag->patient_id,
            'type' => $this->redFlag->type,
            'priority' => $this->redFlag->priority,
        ];
    }
}
