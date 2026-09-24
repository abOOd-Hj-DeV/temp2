<?php

namespace App\Notifications;

use App\Models\SessionRecommendation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SessionRecommendationNotification extends Notification
{
    use Queueable;

    public function __construct(private SessionRecommendation $recommendation) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'session_recommendation',
            'session_id' => $this->recommendation->session_id,
            'recommendation_id' => $this->recommendation->id,
            'package_id' => $this->recommendation->package_id,
            'program_id' => $this->recommendation->program_id,
            'revision' => $this->recommendation->revision,
        ];
    }
}
