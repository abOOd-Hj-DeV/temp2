<?php

namespace App\Notifications;

use App\Models\Assessment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AssessmentCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private Assessment $assessment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'assessment_completed',
            'assessment_id' => $this->assessment->id,
            'type' => $this->assessment->type->value ?? $this->assessment->type,
            'score' => $this->assessment->score,
            'completed_at' => $this->assessment->completed_at?->toISOString(),
        ];
    }
}
