<?php

namespace App\Notifications;

use App\Models\TherapistSwitch;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TherapistSwitchDecidedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private TherapistSwitch $switch,
        private string $kind = 'therapist_switch',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'switch_id' => $this->switch->id,
            'status' => $this->switch->status,
            'therapist_decision' => $this->switch->therapist_decision,
            'old_therapist_id' => $this->switch->old_therapist_id,
            'new_therapist_id' => $this->switch->new_therapist_id,
            'cancelled_session_ids' => $this->switch->cancelled_session_ids ?? [],
        ];
    }
}
