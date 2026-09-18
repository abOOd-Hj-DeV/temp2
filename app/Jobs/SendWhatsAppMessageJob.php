<?php

namespace App\Jobs;

use App\Services\Messaging\WhatsAppSenderInterface;
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
        if (! $whatsApp->send($this->phoneNumber, $this->message)) {
            throw new \RuntimeException('WhatsApp provider rejected the message.');
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::critical('WhatsApp message permanently failed', $this->context + [
            'attempts' => $this->tries,
            'error' => $e->getMessage(),
        ]);
    }
}
