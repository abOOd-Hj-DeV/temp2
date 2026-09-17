<?php

namespace App\Notifications;

use App\Models\Therapist;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TherapistApprovalNotification extends Notification
{
    use Queueable;

    public function __construct(private Therapist $therapist) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'therapist_approval',
            'therapist_id' => $this->therapist->user_id,
            'approval_status' => $this->therapist->approval_status?->value,
        ];
    }
}
