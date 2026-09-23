<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-runs a NotificationService method whose first attempt failed after the
 * primary write had already been committed. Delivery is idempotent per
 * (event, recipient), so the retry can never duplicate what already went out.
 */
class RetryNotificationJob implements ShouldQueue
{
    use Queueable;

    /** @var array<int, int> seconds */
    public array $backoff = [60, 300, 900, 3600];

    /** @var array<int, mixed> */
    private array $args;

    public function __construct(
        public string $method,
        array $args,
    ) {
        $this->args = array_map(fn ($arg) => $this->getSerializedPropertyValue($arg), $args);
        $this->afterCommit();
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(7);
    }

    public function handle(NotificationService $notifications): void
    {
        try {
            $args = array_map(fn ($arg) => $this->getRestoredPropertyValue($arg), $this->args);
        } catch (ModelNotFoundException $e) {
            Log::warning('Notification retry dropped: subject no longer exists', [
                'method' => $this->method,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $notifications->{$this->method}(...$args);
    }

    public function failed(\Throwable $e): void
    {
        Log::critical('Notification permanently failed', [
            'method' => $this->method,
            'error' => $e->getMessage(),
        ]);
    }
}
