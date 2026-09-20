<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Patient\AccountAnonymizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Anonymises accounts whose deletion grace period has elapsed. Records are
 * never hard-deleted so sessions and payments survive for audit.
 * Scheduled daily — see bootstrap/app.php.
 */
class PruneScheduledDeletionsJob implements ShouldQueue
{
    use Queueable;

    public function handle(AccountAnonymizer $anonymizer): void
    {
        $count = 0;

        User::whereNotNull('deletion_scheduled_at')
            ->where('deletion_scheduled_at', '<=', now())
            ->whereNull('anonymized_at')
            ->each(function (User $user) use ($anonymizer, &$count) {
                try {
                    $anonymizer->anonymize($user);
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                    Log::error('Anonymization failed; will retry on the next run', ['user_id' => $user->id]);
                }
            });

        if ($count > 0) {
            Log::info('Anonymised accounts past deletion grace period', ['count' => $count]);
        }
    }
}
