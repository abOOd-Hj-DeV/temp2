<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Models\TherapySession;
use App\Models\User;
use App\Services\Files\AccountFileFence;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Support\SessionClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reliable outbound WhatsApp delivery: provider failures are retried with
 * backoff instead of being lost with the originating HTTP request. When the
 * context carries a `notification_log_id`, every outcome is written back to
 * that ledger row.
 * Committed delivery suppresses replay; provider acceptance before a ledger
 * commit still has an at-least-once crash window.
 */
class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> seconds */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public string $phoneNumber,
        public string $message,
        public array $context = [],
    ) {
        $this->afterCommit();
    }

    public function handle(WhatsAppSenderInterface $whatsApp): void
    {
        if (isset($this->context['notification_log_id'])) {
            $error = null;
            DB::transaction(function () use ($whatsApp, &$error): void {
                $log = $this->log();
                if ($log === null) {
                    return; // Erasure removed the durable recipient ledger.
                }
                $user = User::whereKey($log->user_id)->lockForUpdate()->first();
                if (! $user || ! $user->is_active || AccountFileFence::erasing($user->id, $user)) {
                    return;
                }
                $log = NotificationLog::whereKey($log->id)->lockForUpdate()->first();
                if (! $log || $log->channel !== NotificationLog::CHANNEL_WHATSAPP
                    || in_array($log->status, [NotificationLog::STATUS_SENT, NotificationLog::STATUS_SKIPPED], true)) {
                    return;
                }
                try {
                    $this->deliver($whatsApp);
                } catch (\Throwable $e) {
                    $error = $e; // Commit retry ledger state, then propagate to the worker.
                }
            });
            if ($error !== null) {
                throw $error;
            }

            return;
        }
        $this->deliver($whatsApp);
    }

    private function deliver(WhatsAppSenderInterface $whatsApp): void
    {
        if (isset($this->context['schedule_key'], $this->context['session_id'])) {
            $session = TherapySession::find($this->context['session_id']);
            $instant = $session ? SessionClock::fromStored($session->session_date, (string) $session->session_time)->startOfMinute() : null;
            if ($instant === null || $session->status->value !== 'confirmed' || $instant->lessThanOrEqualTo(now())
                || ! hash_equals($this->context['schedule_key'], hash('sha256', $instant->toIso8601String()))) {
                $this->log()?->update(['status' => NotificationLog::STATUS_SKIPPED, 'error' => 'Appointment no longer current or upcoming.']);

                return;
            }
        }

        try {
            $sent = $whatsApp->send($this->phoneNumber, $this->message);
        } catch (\Throwable $e) {
            $this->log()?->markFailed($e->getMessage());

            throw $e;
        }

        if ($sent) {
            $this->log()?->markSent();

            return;
        }

        $this->log()?->markFailed('WhatsApp provider rejected the message.');

        throw new \RuntimeException('WhatsApp provider rejected the message.');
    }

    public function failed(\Throwable $e): void
    {
        $this->log()?->markPermanentlyFailed($e->getMessage());

        Log::critical('WhatsApp message permanently failed', $this->context + [
            'attempts' => $this->tries,
            'reason' => 'provider_failure',
        ]);
    }

    private function log(): ?NotificationLog
    {
        $id = $this->context['notification_log_id'] ?? null;

        return is_string($id) ? NotificationLog::find($id) : null;
    }
}
