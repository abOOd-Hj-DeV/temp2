<?php

namespace App\Console\Commands;

use App\Services\Notifications\OperationsHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorOperations extends Command
{
    protected $signature = 'ops:monitor';

    protected $description = 'Emit redacted diagnostics and signal unhealthy delivery infrastructure';

    public function handle(OperationsHealth $health): int
    {
        $diagnostics = $health->diagnostics();
        $alert = ! $diagnostics['ready'] || ! $diagnostics['worker_fresh'] || ! $diagnostics['scheduler_fresh']
            || ($diagnostics['diagnostics_unavailable'] ?? false)
            || ($diagnostics['outbox_overdue'] ?? 0) > 0 || ($diagnostics['failed_jobs'] ?? 0) > 0
            || ($diagnostics['queue_size'] ?? 0) >= (int) config('operations.queue_alert_size')
            || ! ($diagnostics['local_storage_writable'] ?? false);

        Log::log($alert ? 'critical' : 'info', 'Operations health snapshot', $diagnostics);
        $this->line(json_encode($diagnostics, JSON_THROW_ON_ERROR));

        return $alert ? self::FAILURE : self::SUCCESS;
    }
}
