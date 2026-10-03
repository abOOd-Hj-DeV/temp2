<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ClinicalUpdateNotification extends Notification
{
    public function __construct(private string $kind, private string $entityId, private string $eventId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['kind' => $this->kind, 'entity_id' => $this->entityId, 'event_id' => $this->eventId];
    }
}
