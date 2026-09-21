<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Messaging\WhatsAppSenderInterface;
use App\Support\DurableQueue;
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

        $fallback = $this->job?->getConnectionName() === 'sync' ? DurableQueue::fallbackConnection() : null;

        if ($fallback === null) {
            $this->log()?->markFailed('WhatsApp provider rejected the message.');

            throw new \RuntimeException('WhatsApp provider rejected the message.');
        }

        // Running inline: hand the retries to a worker instead of failing the request.
        static::dispatch($this->phoneNumber, $this->message, $this->context)
            ->onConnection($fallback)
            ->delay(now()->addSeconds($this->backoff[0]));

        $this->log()?->markFailed('WhatsApp provider rejected the message; deferred to the durable queue.');

        Log::warning('WhatsApp message deferred to the durable queue', $this->context + ['connection' => $fallback]);
    }

    public function failed(\Throwable $e): void
    {
        $this->log()?->markPermanentlyFailed($e->getMessage());

        Log::critical('WhatsApp message permanently failed', $this->context + [
            'attempts' => $this->tries,
            'error' => $e->getMessage(),
        ]);
    }

    private function log(): ?NotificationLog
    {
        $id = $this->context['notification_log_id'] ?? null;

        return is_string($id) ? NotificationLog::find($id) : null;
    }
}
