<?php

namespace App\Jobs;

use App\Services\Messaging\WhatsAppSenderInterface;
use App\Support\DurableQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reliable outbound WhatsApp delivery: provider failures are retried with
 * backoff instead of being lost with the originating HTTP request.
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
        if ($whatsApp->send($this->phoneNumber, $this->message)) {
            return;
        }

        $fallback = $this->job?->getConnectionName() === 'sync' ? DurableQueue::fallbackConnection() : null;

        if ($fallback === null) {
            throw new \RuntimeException('WhatsApp provider rejected the message.');
        }

        // Running inline: hand the retries to a worker instead of failing the request.
        static::dispatch($this->phoneNumber, $this->message, $this->context)
            ->onConnection($fallback)
            ->delay(now()->addSeconds($this->backoff[0]));

        Log::warning('WhatsApp message deferred to the durable queue', $this->context + ['connection' => $fallback]);
    }

    public function failed(\Throwable $e): void
    {
        Log::critical('WhatsApp message permanently failed', $this->context + [
            'attempts' => $this->tries,
            'error' => $e->getMessage(),
        ]);
    }
}
