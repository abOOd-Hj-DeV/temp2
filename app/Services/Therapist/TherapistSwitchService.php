<?php

namespace App\Services\Therapist;

use App\Enums\ApprovalStatus;
use App\Enums\SessionStatus;
use App\Exceptions\ConflictException;
use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapistSwitch;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\RedFlagService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Patient-initiated therapist change. The requested therapist accepts or
 * declines first, then the Head Master (clinical supervisor/admin) gives the
 * final decision; on approval the patient is re-assigned and the new therapist's capacity
 * is re-checked under a row lock. Existing sessions with the previous
 * therapist are left untouched (they can be cancelled independently).
 */
class TherapistSwitchService
{
    public const THERAPIST_ACCEPTED = 'accepted';

    public const THERAPIST_DECLINED = 'declined';

    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
        private NotificationService $notifications,
        private AuditLogService $audit,
    ) {}

    public function request(Patient $patient, string $newTherapistId, string $reason, User $actor): TherapistSwitch
    {
        $subscription = $this->subscriptions->activeForPatient($patient->user_id);

        if (! $subscription) {
            throw ValidationException::withMessages(['subscription' => 'An active subscription is required to switch therapist.']);
        }

        if (! $patient->therapist_id) {
            throw ValidationException::withMessages(['therapist' => 'You do not have an assigned therapist yet; book a session instead.']);
        }

        if ($patient->therapist_id === $newTherapistId) {
            throw ValidationException::withMessages(['new_therapist_id' => 'This is already your therapist.']);
        }

        if ($this->hasSessionWithinLockWindow($patient)) {
            throw ValidationException::withMessages([
                'therapist' => sprintf(
                    'You have a session with your current therapist within the next %d hours; request the switch after it.',
                    $this->switchLockHours()
                ),
            ]);
        }

        $target = Therapist::whereKey($newTherapistId)->first();

        if (! $target || $target->approval_status !== ApprovalStatus::APPROVED) {
            throw ValidationException::withMessages(['new_therapist_id' => 'The selected therapist is not available.']);
        }

        if (! $target->can_accept_new_clients) {
            throw ValidationException::withMessages(['new_therapist_id' => 'The selected therapist is not accepting new clients.']);
        }

        try {
            $switch = DB::transaction(function () use ($patient, $target, $subscription, $reason) {
                // Serialise per patient; therapist_switches_requested_unique is the backstop.
                Patient::whereKey($patient->user_id)->lockForUpdate()->firstOrFail();

                $open = TherapistSwitch::where('patient_id', $patient->user_id)
                    ->where('status', 'requested')
                    ->exists();

                if ($open) {
                    throw new ConflictException('You already have a pending therapist switch request.');
                }

                return TherapistSwitch::create([
                    'id' => (string) Str::uuid(),
                    'patient_id' => $patient->user_id,
                    'old_therapist_id' => $patient->therapist_id,
                    'new_therapist_id' => $target->user_id,
                    'subscription_id' => $subscription->id,
                    'reason' => $reason,
                    'timestamp' => now(),
                    'status' => 'requested',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException('You already have a pending therapist switch request.');
        }

        $this->notifications->deliver('therapistSwitchRequested', $switch);

        $this->audit->record($actor, AuditLogService::THERAPIST_SWITCH_REQUESTED, $switch->id, [
            'from' => $switch->old_therapist_id, 'to' => $switch->new_therapist_id,
        ]);

        return $switch;
    }

    private function switchLockHours(): int
    {
        return (int) config('sakina.therapist_switch_lock_hours', 48);
    }

    /**
     * A switch is not allowed while a live (pending/confirmed) session with
     * the current therapist starts within the lock window.
     */
    private function hasSessionWithinLockWindow(Patient $patient): bool
    {
        $from = now();
        $to = now()->addHours($this->switchLockHours());

        return TherapySession::where('patient_id', $patient->user_id)
            ->where('therapist_id', $patient->therapist_id)
            ->whereIn('status', [SessionStatus::PENDING->value, SessionStatus::CONFIRMED->value])
            ->whereDate('session_date', '>=', $from->toDateString())
            ->whereDate('session_date', '<=', $to->toDateString())
            ->get()
            ->contains(function (TherapySession $session) use ($from, $to) {
                $startsAt = Carbon::parse($session->session_date->toDateString().' '.substr((string) $session->session_time, 0, 5));

                return $startsAt->between($from, $to);
            });
    }

    /**
     * Step 1: the requested therapist accepts or declines taking the patient.
     * Declining closes the request; accepting hands it to the Head Master
     * (clinical supervisor / admin) for the final decision.
     */
    public function therapistDecide(TherapistSwitch $switch, bool $accept, User $therapist, ?string $note = null): TherapistSwitch
    {
        $switch = DB::transaction(function () use ($switch, $accept, $therapist, $note) {
            $locked = TherapistSwitch::whereKey($switch->id)->lockForUpdate()->firstOrFail();

            if ($locked->new_therapist_id !== $therapist->id) {
                throw new AuthorizationException('This switch request is not addressed to you.');
            }

            if ($locked->status !== 'requested' || $locked->therapist_decision !== null) {
                throw new ConflictException('This switch request was already answered.');
            }

            $locked->update([
                'therapist_decision' => $accept ? self::THERAPIST_ACCEPTED : self::THERAPIST_DECLINED,
                'therapist_decided_at' => now(),
                'status' => $accept ? 'requested' : 'rejected',
                'decided_by' => $accept ? null : $therapist->id,
                'decided_at' => $accept ? null : now(),
            ]);

            $this->audit->record($therapist, AuditLogService::THERAPIST_SWITCH_THERAPIST_DECIDED, $locked->id, [
                'accepted' => $accept, 'note' => $note,
            ]);

            return $locked->refresh();
        });

        if ($switch->status === 'rejected') {
            $this->notifications->deliver('therapistSwitchDecided', $switch);
        } else {
            $this->notifications->deliver('therapistSwitchAwaitingSupervisor', $switch, $this->supervisors());
        }

        return $switch;
    }

    /**
     * Step 2: Head Master / admin final decision. Only reachable after the
     * target therapist accepted; exactly one decision wins under the row lock.
     */
    public function decide(TherapistSwitch $switch, bool $approve, User $admin, ?string $note = null): TherapistSwitch
    {
        $switch = DB::transaction(function () use ($switch, $approve, $admin, $note) {
            $locked = TherapistSwitch::whereKey($switch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'requested') {
                throw new ConflictException('This switch request was already decided.');
            }

            if ($locked->therapist_decision !== self::THERAPIST_ACCEPTED) {
                throw ValidationException::withMessages(['switch' => 'The requested therapist has not accepted this patient yet.']);
            }

            if ($approve) {
                $target = Therapist::whereKey($locked->new_therapist_id)->lockForUpdate()->firstOrFail();

                if ($target->approval_status !== ApprovalStatus::APPROVED) {
                    throw ValidationException::withMessages(['therapist' => 'Target therapist is no longer approved.']);
                }

                $alreadyClient = $target->sessions()
                    ->where('patient_id', $locked->patient_id)
                    ->where('status', '!=', SessionStatus::CANCELLED->value)
                    ->exists();

                if (! $alreadyClient && $target->clients_count >= $target->clients_limit) {
                    throw ValidationException::withMessages(['therapist' => 'Target therapist has reached their client limit.']);
                }

                Patient::whereKey($locked->patient_id)->lockForUpdate()->firstOrFail();
                Patient::whereKey($locked->patient_id)->update(['therapist_id' => $target->user_id]);
            }

            $locked->update([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $admin->id,
                'decided_at' => now(),
            ]);

            $this->audit->record($admin, AuditLogService::THERAPIST_SWITCH_DECIDED, $locked->id, [
                'approved' => $approve, 'note' => $note,
            ]);

            return $locked->refresh();
        });

        $this->notifications->deliver('therapistSwitchDecided', $switch);

        return $switch;
    }

    private function supervisors(): Collection
    {
        return User::query()
            ->whereIn('role', array_map(fn ($r) => $r->value, RedFlagService::CLINICAL_STAFF_ROLES))
            ->where('is_active', true)
            ->get();
    }

    public function toArray(TherapistSwitch $switch): array
    {
        return [
            'id' => $switch->id,
            'patient_id' => $switch->patient_id,
            'old_therapist_id' => $switch->old_therapist_id,
            'new_therapist_id' => $switch->new_therapist_id,
            'subscription_id' => $switch->subscription_id,
            'reason' => $switch->reason,
            'status' => $switch->status,
            'therapist_decision' => $switch->therapist_decision,
            'therapist_decided_at' => $switch->therapist_decided_at?->toISOString(),
            'decided_at' => $switch->decided_at?->toISOString(),
            'requested_at' => $switch->timestamp?->toISOString(),
        ];
    }
}
