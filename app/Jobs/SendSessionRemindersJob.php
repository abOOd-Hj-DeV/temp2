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
 * minutes (bootstrap/app.php). Each window is claimed atomically so a reminder
 * is sent at most once; the claim is released on failure (up to MAX_ATTEMPTS)
 * so a transient error does not silently lose the reminder.
 */
class SendSessionRemindersJob implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    public function handle(SessionRepositoryInterface $sessions, NotificationService $notifications): void
    {
        $this->window($sessions, $notifications, 'reminder_sent', 'reminder_attempts', '24h', now()->addHours(23), now()->addHours(24));
        $this->window($sessions, $notifications, 'reminder_1h_sent', 'reminder_1h_attempts', '1h', now()->addMinutes(45), now()->addHours(1));
    }

    private function window(
        SessionRepositoryInterface $sessions,
        NotificationService $notifications,
        string $flag,
        string $attemptsColumn,
        string $label,
        $from,
        $to,
    ): void {
        foreach ($sessions->dueForReminder($from, $to, $flag) as $session) {
            $claimed = TherapySession::whereKey($session->id)
                ->where($flag, false)
                ->where($attemptsColumn, '<', self::MAX_ATTEMPTS)
                ->update([$flag => true, $attemptsColumn => $session->{$attemptsColumn} + 1]);

            if ($claimed === 0) {
                continue;
            }

            try {
                $notifications->sessionReminder($session, $label);
            } catch (\Throwable $e) {
                TherapySession::whereKey($session->id)->update([$flag => false]);

                Log::error('Session reminder failed', [
                    'session_id' => $session->id,
                    'window' => $label,
                    'attempt' => $session->{$attemptsColumn} + 1,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
