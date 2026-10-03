<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Never interpret an exception after commit as permission to delete committed bytes. */
class RollbackFileCleanup
{
    public static function register(string $disk, string $path): void
    {
        $removed = false;
        $cleanup = function () use ($disk, $path, &$removed): void {
            if ($removed) {
                return;
            }
            $removed = true;
            try {
                if (Storage::disk($disk)->delete($path)) {
                    return;
                }
            } catch (\Throwable) {
                // A cleanup outage must not mask the domain failure or disclose paths.
            }
            Log::warning('Private file rollback cleanup failed.', ['disk' => $disk, 'path_hash' => hash('sha256', $path)]);
        };

        // Laravel discards a committed child's rollback callbacks when its parent
        // rolls back. Bind to every enclosing transaction too, once per upload.
        foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback($cleanup);
            }
        }
    }
}
