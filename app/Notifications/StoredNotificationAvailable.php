<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class StoredNotificationAvailable extends Notification
{
    public function __construct(string $notificationId)
    {
        $this->id = $notificationId;
    }

    public function via(object $notifiable): array
    {
        return ['broadcast'];
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->id, 'kind' => 'notification_available'];
    }
}
