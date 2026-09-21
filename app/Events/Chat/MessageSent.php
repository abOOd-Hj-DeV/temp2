<?php

namespace App\Events\Chat;

use App\Models\Message;
use App\Services\Chat\ChatService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the conversation's private channel and to the receiver's
 * personal channel (unread badge). Authorization lives in routes/channels.php.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.conversation.{$this->message->conversation_id}"),
            new PrivateChannel("App.Models.User.{$this->message->receiver_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    public function broadcastWith(): array
    {
        return ['message' => app(ChatService::class)->toArray($this->message)];
    }
}
