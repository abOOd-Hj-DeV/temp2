<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Append-only trail of sensitive actions (payment decisions, approvals,
 * status transitions, account lifecycle). Never throws: an audit failure
 * must not roll back the business operation it describes.
 */
class AuditLogService
{
    public const PAYMENT_REVIEWED = 'payment.reviewed';

    public const THERAPIST_APPROVAL_DECIDED = 'therapist.approval_decided';

    public const THERAPIST_LIMIT_CHANGED = 'therapist.limit_changed';

    public const SESSION_BOOKED = 'session.booked';

    public const SESSION_TRANSITIONED = 'session.transitioned';

    public const SUBSCRIPTION_CREATED = 'subscription.created';

    public const RED_FLAG_UPDATED = 'red_flag.updated';

    public const RED_FLAG_ASSIGNED = 'red_flag.assigned';

    public const THERAPIST_SWITCH_REQUESTED = 'therapist_switch.requested';

    public const THERAPIST_SWITCH_DECIDED = 'therapist_switch.decided';

    public const WITHDRAWAL_REQUESTED = 'withdrawal.requested';

    public const WITHDRAWAL_DECIDED = 'withdrawal.decided';

    public const LOGIN_SUCCEEDED = 'auth.login';

    public const PASSWORD_RESET = 'auth.password_reset';

    public const ACCOUNT_DELETION_REQUESTED = 'account.deletion_requested';

    public const ACCOUNT_DELETION_CANCELLED = 'account.deletion_cancelled';

    public const ACCOUNT_ANONYMIZED = 'account.anonymized';

    public const FILE_DOWNLOADED = 'file.downloaded';

    public function record(User|string $actor, string $action, ?string $entityId = null, array $details = []): void
    {
        try {
            AuditLog::create([
                'id' => (string) Str::uuid(),
                'user_id' => $actor instanceof User ? $actor->id : $actor,
                'action' => $action,
                'entity_id' => $entityId,
                'details' => $details ?: null,
                'timestamp' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
