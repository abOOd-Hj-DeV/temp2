<?php

namespace App\Services\Notifications;

use App\Jobs\BroadcastStoredNotificationJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Files\AccountFileFence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private NotificationOutboxService $outbox) {}

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
            $created = DB::transaction(function () use ($user, $notification, $eventKey) {
                $user = User::whereKey($user->id)->lockForUpdate()->first();
                if (! $user || ! $user->is_active || AccountFileFence::erasing($user->id, $user)) {
                    return false;
                }
                $user->notifyNow($notification, ['database']);

                NotificationLog::create($this->row($user, NotificationLog::CHANNEL_IN_APP, $eventKey, NotificationLog::STATUS_SENT, [
                    'notification_id' => $notification->id,
                ]) + ['attempts' => 1, 'sent_at' => now()]);

                $this->outbox->stage(new BroadcastStoredNotificationJob($user->id, $notification->id), 'broadcast:'.$notification->id);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return $created;
    }

    /**
     * Queue a WhatsApp text for the recipient (retried with backoff by the
     * job). A recipient without a number is recorded as skipped; enqueueing
     * failures remain in the database outbox independently of in-app delivery.
     */
    public function whatsApp(?User $user, string $message, string $eventKey, array $context = []): void
    {
        if (! $user instanceof User) {
            return;
        }

        DB::transaction(function () use ($user, $message, $eventKey, $context): void {
            $recipient = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $recipient->is_active || AccountFileFence::erasing($recipient->id, $recipient)) {
                return;
            }
            $log = NotificationLog::where('user_id', $recipient->id)
                ->where('channel', NotificationLog::CHANNEL_WHATSAPP)->where('event_key', $eventKey)->first();

            if ($log?->status === NotificationLog::STATUS_SENT) {
                return;
            }

            $log ??= NotificationLog::create($this->row($recipient, NotificationLog::CHANNEL_WHATSAPP, $eventKey, NotificationLog::STATUS_QUEUED, $context));

            if (empty($recipient->whatsapp_number)) {
                $log->update(['status' => NotificationLog::STATUS_SKIPPED, 'error' => 'Recipient has no WhatsApp number.']);

                return;
            }

            $log->update(['status' => NotificationLog::STATUS_QUEUED, 'error' => null]);
            $this->outbox->stage(
                new SendWhatsAppMessageJob($recipient->whatsapp_number, $message, $this->safeContext($context) + ['notification_log_id' => $log->id]),
                "whatsapp:{$eventKey}:{$recipient->id}"
            );
        });
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
            'context' => $this->safeContext($context) ?: null,
        ];
    }

    private function safeContext(array $context): array
    {
        return array_filter(array_intersect_key($context, array_flip([
            'notification_id', 'red_flag_id', 'session_id', 'payment_id', 'reviewer_id',
            'therapist_id', 'escalated_to', 'window', 'schedule_key',
        ])), fn ($value) => is_string($value) && (Str::isUuid($value) || in_array($value, ['1h', '24h'], true) || preg_match('/^[a-f0-9]{64}$/D', $value)));
    }
}
