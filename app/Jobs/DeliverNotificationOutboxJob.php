<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationOutboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverNotificationOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public string $outboxId, public string $claimToken) {}

    public function handle(NotificationOutboxService $outbox): void
    {
        $outbox->execute($this->outboxId, $this->claimToken);
    }
}
