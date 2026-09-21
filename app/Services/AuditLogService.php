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

    public const SUBSCRIPTION_CANCELLED = 'subscription.cancelled';

    public const RED_FLAG_UPDATED = 'red_flag.updated';

    public const RED_FLAG_ASSIGNED = 'red_flag.assigned';

    public const THERAPIST_SWITCH_REQUESTED = 'therapist_switch.requested';

    public const THERAPIST_SWITCH_THERAPIST_DECIDED = 'therapist_switch.therapist_decided';

    public const THERAPIST_SWITCH_DECIDED = 'therapist_switch.decided';

    public const SESSION_RESCHEDULE_REQUESTED = 'session.reschedule_requested';

    public const SESSION_RESCHEDULE_DECIDED = 'session.reschedule_decided';

    public const SESSION_ATTENDANCE_CONFIRMED = 'session.attendance_confirmed';

    public const SESSION_REPORT_REVISED = 'session.report_revised';

    public const PACKAGE_CREATED = 'package.created';

    public const PACKAGE_UPDATED = 'package.updated';

    public const PACKAGE_PUBLISHED = 'package.published';

    public const PACKAGE_UNPUBLISHED = 'package.unpublished';

    public const WITHDRAWAL_REQUESTED = 'withdrawal.requested';

    public const WITHDRAWAL_DECIDED = 'withdrawal.decided';

    public const LOGIN_SUCCEEDED = 'auth.login';

    public const PASSWORD_RESET = 'auth.password_reset';

    public const LOGOUT_ALL = 'auth.logout_all';

    public const REFRESH_TOKEN_REPLAYED = 'auth.refresh_token_replayed';

    public const ACCOUNT_DELETION_REQUESTED = 'account.deletion_requested';

    public const ACCOUNT_DELETION_CANCELLED = 'account.deletion_cancelled';

    public const ACCOUNT_ANONYMIZED = 'account.anonymized';

    public const FILE_DOWNLOADED = 'file.downloaded';

    public const CONVERSATION_OPENED = 'chat.conversation_opened';

    public const CHAT_ATTACHMENT_SENT = 'chat.attachment_sent';

    public const PATIENT_RECORD_VIEWED = 'patient.record_viewed';

    public const AUDIT_LOG_QUERIED = 'audit.queried';

    public const PROGRAM_CREATED = 'program.created';

    public const PROGRAM_UPDATED = 'program.updated';

    public const PROGRAM_DELETED = 'program.deleted';

    public const MODULE_CREATED = 'module.created';

    public const MODULE_UPDATED = 'module.updated';

    public const MODULE_DELETED = 'module.deleted';

    public const STAFF_ACCOUNT_CREATED = 'staff.account_created';

    public const STAFF_INVITATION_RESENT = 'staff.invitation_resent';

    public const STAFF_ACCOUNT_ACTIVATED = 'staff.account_activated';

    public const STAFF_ACCOUNT_DEACTIVATED = 'staff.account_deactivated';

    public const STAFF_ACCOUNT_REACTIVATED = 'staff.account_reactivated';

    /** Every action name declared on this class, for filter validation. */
    public static function actions(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

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
