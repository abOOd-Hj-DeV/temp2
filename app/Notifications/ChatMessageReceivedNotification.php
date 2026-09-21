<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app "you have new messages" alert. Carries no message body: the
 * notifications table is not part of the encrypted chat store.
 */
class ChatMessageReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(private Message $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'chat_message_received',
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'has_attachment' => $this->message->hasAttachment(),
            'sent_at' => $this->message->timestamp?->toISOString(),
        ];
    }
}
