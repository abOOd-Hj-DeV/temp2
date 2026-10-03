<?php

namespace App\Services\Notifications;

use Illuminate\Foundation\Bus\PendingDispatch;

class OutboxPendingDispatch extends PendingDispatch
{
    public function __construct($job, private string $dedupeKey)
    {
        parent::__construct($job);
    }

    public function __destruct()
    {
        app(NotificationOutboxService::class)->stage($this->job, $this->dedupeKey);
    }
}
