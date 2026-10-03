<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\StoredNotificationAvailable;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;

class BroadcastStoredNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $userId, public string $notificationId) {}

    public function handle(Factory $broadcast): void
    {
        $user = User::whereKey($this->userId)->where('is_active', true)->whereNotNull('phone_verified_at')
            ->whereNull('anonymized_at')->first();

        if ($user === null || ! DatabaseNotification::whereKey($this->notificationId)->where('notifiable_id', $user->id)->exists()) {
            return;
        }

        if (app()->isProduction() && config('broadcasting.default') !== 'reverb') {
            throw new \RuntimeException('Production notification broadcasting is not configured.');
        }

        $event = new BroadcastNotificationCreated($user, new StoredNotificationAvailable($this->notificationId));
        $broadcast->connection()->broadcast($event->broadcastOn(), $event->broadcastAs(), $event->broadcastWith());
    }
}
