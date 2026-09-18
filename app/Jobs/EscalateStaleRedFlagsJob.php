<?php

namespace App\Jobs;

use App\Enums\RedFlagPriority;
use App\Enums\UserRole;
use App\Models\RedFlag;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for the clinical response chain: any high-priority or unassigned
 * red flag still open after the configured window is broadcast to every
 * active clinical staff member, exactly once.
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

        $staff = User::role([
            UserRole::CLINICAL_SUPERVISOR->value,
            UserRole::SUPER_ADMIN->value,
            UserRole::ADMIN->value,
        ])->where('is_active', true)->get();

        foreach ($stale as $flag) {
            $claimed = RedFlag::whereKey($flag->id)->whereNull('escalated_at')->update(['escalated_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            if ($staff->isEmpty()) {
                Log::critical('Stale red flag has no clinical staff to escalate to', ['red_flag_id' => $flag->id]);

                continue;
            }

            $notifications->redFlagEscalated($flag, $staff);

            Log::warning('Red flag escalated', [
                'red_flag_id' => $flag->id,
                'priority' => $flag->priority->value,
                'open_minutes' => $flag->created_at?->diffInMinutes(now()),
                'staff_notified' => $staff->count(),
            ]);
        }
    }
}
