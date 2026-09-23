<?php

namespace App\Jobs;

use App\Enums\RedFlagPriority;
use App\Models\RedFlag;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RedFlagService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for the clinical response chain: any high-priority or unassigned
 * red flag still open after the configured window is broadcast to every
 * active clinical staff member. `escalated_at` is only stamped after the
 * recipients were notified; with no staff on record the flag stays
 * eligible and is retried on the next run (attempt counter grows so the
 * outage is visible), instead of being silently consumed.
 */
class EscalateStaleRedFlagsJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationService $notifications): void
    {
        $cutoff = now()->subMinutes((int) config('sakina.red_flag_escalation_minutes', 60));

        $stale = RedFlag::query()
            ->where('status', 'open')
            ->whereNull('escalated_at')
            ->where('created_at', '<=', $cutoff)
            ->where(function ($q) {
                $q->where('priority', RedFlagPriority::HIGH->value)
                    ->orWhereNull('assigned_to');
            })
            ->orderBy('created_at')
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $staff = User::query()
            ->whereIn('role', array_map(fn ($r) => $r->value, RedFlagService::CLINICAL_STAFF_ROLES))
            ->where('is_active', true)
            ->get();

        foreach ($stale as $flag) {
            $this->escalate($flag, $staff, $notifications);
        }
    }

    private function escalate(RedFlag $flag, Collection $staff, NotificationService $notifications): void
    {
        $attempt = DB::transaction(function () use ($flag): ?int {
            $locked = RedFlag::whereKey($flag->id)->whereNull('escalated_at')->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            $attempt = $locked->escalation_attempts + 1;
            RedFlag::whereKey($locked->id)->update(['escalation_attempts' => $attempt]);

            return $attempt;
        });

        if ($attempt === null) {
            return;
        }

        if ($staff->isEmpty()) {
            Log::critical('Stale red flag has no clinical staff to escalate to; will retry', [
                'red_flag_id' => $flag->id,
                'attempt' => $attempt,
            ]);

            return;
        }

        $delivered = $notifications->redFlagEscalated($flag, $staff);

        RedFlag::whereKey($flag->id)->whereNull('escalated_at')->update(['escalated_at' => now()]);

        Log::warning('Red flag escalated', [
            'red_flag_id' => $flag->id,
            'priority' => $flag->priority->value,
            'open_minutes' => $flag->created_at?->diffInMinutes(now()),
            'attempt' => $attempt,
            'staff_notified' => $delivered,
        ]);
    }
}
