<?php

namespace App\Services\Notifications;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Channel-level delivery behind NotificationService. Every attempt leaves a
 * NotificationLog row so operations can see what reached whom, without the
 * ledger ever holding message text or phone numbers.
 *
 * In-app delivery is idempotent per (event, recipient): the database
 * notification id is derived from the event key, so a replayed job or a
 * retried request cannot produce a second copy. WhatsApp is queued and the
 * job reports the outcome back into the same log row.
 */
class NotificationDispatcher
{
    private const NAMESPACE = '5f5b1e7a-8c3b-4e0d-9a3c-2f1d0b6c7e11';

    /**
     * Store the database notification exactly once per (event, recipient).
     * Returns true only when this call created it.
     */
    public function inApp(?User $user, Notification $notification, string $eventKey): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $id = Uuid::uuid5(self::NAMESPACE, "{$eventKey}|{$user->id}")->toString();

        if (DatabaseNotification::whereKey($id)->exists()) {
            return false;
        }

        $notification->id = $id;

        try {
            DB::transaction(function () use ($user, $notification, $eventKey) {
                $user->notify($notification);

                NotificationLog::create($this->row($user, NotificationLog::CHANNEL_IN_APP, $eventKey, NotificationLog::STATUS_SENT, [
                    'notification_id' => $notification->id,
                ]) + ['attempts' => 1, 'sent_at' => now()]);
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Queue a WhatsApp text for the recipient (retried with backoff by the
     * job). A recipient without a number is recorded as skipped; enqueueing
     * failures are logged so notification problems never abort the caller.
     */
    public function whatsApp(?User $user, string $message, string $eventKey, array $context = []): void
    {
        if (! $user instanceof User) {
            return;
        }

        if (empty($user->whatsapp_number)) {
            $this->logSafely($this->row($user, NotificationLog::CHANNEL_WHATSAPP, $eventKey, NotificationLog::STATUS_SKIPPED, $context) + [
                'error' => 'Recipient has no WhatsApp number.',
            ]);

            return;
        }

        $log = $this->logSafely($this->row($user, NotificationLog::CHANNEL_WHATSAPP, $eventKey, NotificationLog::STATUS_QUEUED, $context));

        try {
            SendWhatsAppMessageJob::dispatch(
                $user->whatsapp_number,
                $message,
                $context + array_filter(['notification_log_id' => $log?->id])
            );
        } catch (\Throwable $e) {
            Log::error('WhatsApp notification failed', $context + ['error' => $e->getMessage()]);
            $log?->markPermanentlyFailed($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function row(User $user, string $channel, string $eventKey, string $status, array $context): array
    {
        return [
            'user_id' => $user->id,
            'channel' => $channel,
            'event' => Str::before($eventKey, ':'),
            'event_key' => $eventKey,
            'status' => $status,
            'context' => $context === [] ? null : $context,
        ];
    }

    private function logSafely(array $attributes): ?NotificationLog
    {
        try {
            return NotificationLog::create($attributes);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
