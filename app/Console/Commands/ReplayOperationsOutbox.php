<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationOutboxService;
use Illuminate\Console\Command;

class ReplayOperationsOutbox extends Command
{
    protected $signature = 'ops:outbox-replay {--limit=100}';

    protected $description = 'Claim and enqueue due notification outbox work';

    public function handle(NotificationOutboxService $outbox): int
    {
        $count = $outbox->replay(max(1, min(1000, (int) $this->option('limit'))));
        $this->info("Examined {$count} due outbox records.");

        return self::SUCCESS;
    }
}
