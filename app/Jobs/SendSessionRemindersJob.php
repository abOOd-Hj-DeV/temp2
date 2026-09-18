<?php

namespace App\Jobs;

use App\Models\TherapySession;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends the 24h and 1h reminders for confirmed sessions. Runs every five
 * minutes (bootstrap/app.php); each window is flagged on the session so a
 * reminder is sent at most once even if the scheduler overlaps.
 */
class SendSessionRemindersJob implements ShouldQueue
{
    use Queueable;

    public function handle(SessionRepositoryInterface $sessions, NotificationService $notifications): void
    {
        $this->window($sessions, $notifications, 'reminder_sent', '24h', now()->addHours(23), now()->addHours(24));
        $this->window($sessions, $notifications, 'reminder_1h_sent', '1h', now()->addMinutes(45), now()->addHours(1));
    }

    private function window(SessionRepositoryInterface $sessions, NotificationService $notifications, string $flag, string $label, $from, $to): void
    {
        foreach ($sessions->dueForReminder($from, $to, $flag) as $session) {
            $claimed = TherapySession::whereKey($session->id)->where($flag, false)->update([$flag => true]);

            if ($claimed === 0) {
                continue;
            }

            try {
                $notifications->sessionReminder($session, $label);
            } catch (\Throwable $e) {
                Log::error('Session reminder failed', ['session_id' => $session->id, 'window' => $label, 'error' => $e->getMessage()]);
            }
        }
    }
}
