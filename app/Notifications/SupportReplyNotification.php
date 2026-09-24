<?php

namespace App\Notifications;

use App\Models\SupportReply;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SupportReplyNotification extends Notification
{
    use Queueable;

    public function __construct(private SupportReply $reply) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'support_reply',
            'support_id' => $this->reply->support_id,
            'reply_id' => $this->reply->id,
            'from_staff' => $this->reply->is_staff,
            'preview' => mb_substr($this->reply->body, 0, 120),
        ];
    }
}
