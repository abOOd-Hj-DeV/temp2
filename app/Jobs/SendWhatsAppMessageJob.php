<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Models\TherapySession;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Support\SessionClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reliable outbound WhatsApp delivery: provider failures are retried with
 * backoff instead of being lost with the originating HTTP request. When the
 * context carries a `notification_log_id`, every outcome is written back to
 * that ledger row.
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
        if (isset($this->context['schedule_key'], $this->context['session_id'])) {
            $session = TherapySession::find($this->context['session_id']);
            if ($session === null || $session->status->value !== 'confirmed' || ! hash_equals($this->context['schedule_key'], hash('sha256', SessionClock::fromStored($session->session_date, (string) $session->session_time)->startOfMinute()->toIso8601String()))) {
                $this->log()?->update(['status' => NotificationLog::STATUS_SKIPPED, 'error' => 'Appointment no longer current.']);

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
