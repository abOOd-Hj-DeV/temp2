<?php

namespace App\Jobs;

use App\Enums\SessionStatus;
use App\Models\TherapySession;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Services\NotificationService;
use App\Support\SessionClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends the 24h and 1h reminders for confirmed sessions. Runs every five
 * minutes (bootstrap/app.php). Claims belong to the current appointment instant.
 */
class SendSessionRemindersJob implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    public function handle(SessionRepositoryInterface $sessions, NotificationService $notifications): void
    {
        $this->window($notifications, '24h', now()->addHours(23), now()->addHours(24));
        $this->window($notifications, '1h', now()->addMinutes(45), now()->addHours(1));
    }

    private function window(
        NotificationService $notifications,
        string $label,
        $from,
        $to,
    ): void {
        $candidates = TherapySession::where('status', SessionStatus::CONFIRMED->value)
            ->whereDate('session_date', '>=', $from->toDateString())
            ->whereDate('session_date', '<=', $to->toDateString())->pluck('id');

        foreach ($candidates as $id) {
            try {
                DB::transaction(function () use ($id, $label, $from, $to, $notifications): void {
                    $session = TherapySession::whereKey($id)->lockForUpdate()->firstOrFail();
                    $instant = SessionClock::fromStored($session->session_date, (string) $session->session_time)->startOfMinute();

                    if ($session->status !== SessionStatus::CONFIRMED || ! $instant->between($from, $to)) {
                        return;
                    }

                    $identity = ['session_id' => $session->id, 'schedule_key' => hash('sha256', $instant->toIso8601String()), 'window' => $label];
                    $state = DB::table('ops_session_reminders')->where($identity)->first();

                    if ($state?->sent_at !== null || ($state?->attempts ?? 0) >= self::MAX_ATTEMPTS) {
                        return;
                    }

                    // The session lock serializes claims with approval of a reschedule.
                    DB::table('ops_session_reminders')->updateOrInsert($identity, [
                        'attempts' => ($state?->attempts ?? 0) + 1, 'sent_at' => now(),
                        'created_at' => $state?->created_at ?? now(), 'updated_at' => now(),
                    ]);
                    $notifications->sessionReminder($session, $label);

                    $flag = $label === '24h' ? 'reminder_sent' : 'reminder_1h_sent';
                    $counter = $label === '24h' ? 'reminder_attempts' : 'reminder_1h_attempts';
                    $session->update([$flag => true, $counter => ($state?->attempts ?? 0) + 1]);
                });
            } catch (\Throwable) {
                Log::error('Session reminder deferred', ['session_id' => $id, 'window' => $label]);
            }
        }
    }
}
