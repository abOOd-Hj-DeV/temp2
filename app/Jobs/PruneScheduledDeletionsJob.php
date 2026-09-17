<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Permanently deletes accounts whose deletion grace period has elapsed.
 * Scheduled daily — see bootstrap/app.php.
 */
class PruneScheduledDeletionsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $deleted = User::whereNotNull('deletion_scheduled_at')
            ->where('deletion_scheduled_at', '<=', now())
            ->delete();

        if ($deleted > 0) {
            Log::info('Purged accounts past deletion grace period', ['count' => $deleted]);
        }
    }
}
