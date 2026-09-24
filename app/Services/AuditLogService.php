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

    public const THERAPIST_BLOCKED_PERIOD_CREATED = 'therapist.blocked_period_created';

    public const THERAPIST_BLOCKED_PERIOD_DELETED = 'therapist.blocked_period_deleted';

    public const SESSION_BOOKED = 'session.booked';

    public const SESSION_TRANSITIONED = 'session.transitioned';

    public const SUBSCRIPTION_CREATED = 'subscription.created';

    public const SUBSCRIPTION_CANCELLED = 'subscription.cancelled';

    public const SESSION_CANCELLED_BY_STAFF = 'session.cancelled_by_staff';

    public const RED_FLAG_UPDATED = 'red_flag.updated';

    public const RED_FLAG_ASSIGNED = 'red_flag.assigned';

    public const THERAPIST_SWITCH_REQUESTED = 'therapist_switch.requested';

    public const THERAPIST_SWITCH_THERAPIST_DECIDED = 'therapist_switch.therapist_decided';

    public const THERAPIST_SWITCH_DECIDED = 'therapist_switch.decided';

    public const SESSION_RESCHEDULE_REQUESTED = 'session.reschedule_requested';

    public const SESSION_RESCHEDULE_DECIDED = 'session.reschedule_decided';

    public const SESSION_ATTENDANCE_CONFIRMED = 'session.attendance_confirmed';

    public const SESSION_REPORT_REVISED = 'session.report_revised';

    public const SESSION_RECOMMENDATION_SAVED = 'session.recommendation_saved';

    public const PACKAGE_CREATED = 'package.created';

    public const PACKAGE_UPDATED = 'package.updated';

    public const PACKAGE_PUBLISHED = 'package.published';

    public const PACKAGE_UNPUBLISHED = 'package.unpublished';

    public const WITHDRAWAL_REQUESTED = 'withdrawal.requested';

    public const WITHDRAWAL_DECIDED = 'withdrawal.decided';

    public const LOGIN_SUCCEEDED = 'auth.login';

    public const PASSWORD_RESET = 'auth.password_reset';

    public const PASSWORD_CHANGED = 'auth.password_changed';

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

    public const MODULE_HIDDEN = 'module.hidden_for_patient';

    public const MODULE_UNHIDDEN = 'module.unhidden_for_patient';

    public const STAFF_ACCOUNT_CREATED = 'staff.account_created';

    public const STAFF_INVITATION_RESENT = 'staff.invitation_resent';

    public const STAFF_ACCOUNT_ACTIVATED = 'staff.account_activated';

    public const STAFF_ACCOUNT_DEACTIVATED = 'staff.account_deactivated';

    public const STAFF_ACCOUNT_REACTIVATED = 'staff.account_reactivated';

    public const SUPPORT_TICKET_CREATED = 'support.ticket_created';

    public const CONTENT_ITEM_CREATED = 'content.item_created';

    public const CONTENT_ITEM_UPDATED = 'content.item_updated';

    public const CONTENT_ITEM_DELETED = 'content.item_deleted';

    public const PARALLEL_LAYER_SAVED = 'parallel_layer.saved';

    public const SUPPORT_TICKET_ASSIGNED = 'support.ticket_assigned';

    public const SUPPORT_TICKET_STATUS = 'support.ticket_status';

    public const SUPPORT_TICKET_REPLIED = 'support.ticket_replied';

    public const REVIEW_CREATED = 'review.created';

    public const REVIEW_UPDATED = 'review.updated';

    public const DOCUMENT_REQUESTED = 'document.requested';

    public const DOCUMENT_SUBMITTED = 'document.submitted';

    public const DOCUMENT_APPROVED = 'document.approved';

    public const DOCUMENT_REJECTED = 'document.rejected';

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
