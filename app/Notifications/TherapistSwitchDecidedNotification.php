<?php

namespace App\Notifications;

use App\Models\TherapistSwitch;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TherapistSwitchDecidedNotification extends Notification
{
    use Queueable;

    public function __construct(private TherapistSwitch $switch) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'therapist_switch',
            'switch_id' => $this->switch->id,
            'status' => $this->switch->status,
            'new_therapist_id' => $this->switch->new_therapist_id,
        ];
    }
}
