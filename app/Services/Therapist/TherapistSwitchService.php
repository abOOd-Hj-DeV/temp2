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
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Patient-initiated therapist change. The request is reviewed by staff;
 * on approval the patient is re-assigned and the new therapist's capacity
 * is re-checked under a row lock. Existing sessions with the previous
 * therapist are left untouched (they can be cancelled independently).
 */
class TherapistSwitchService
{
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

        $switch = DB::transaction(function () use ($patient, $target, $subscription, $reason) {
            $open = TherapistSwitch::where('patient_id', $patient->user_id)
                ->where('status', 'requested')
                ->lockForUpdate()
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

    public function decide(TherapistSwitch $switch, bool $approve, User $admin, ?string $note = null): TherapistSwitch
    {
        $switch = DB::transaction(function () use ($switch, $approve, $admin, $note) {
            $locked = TherapistSwitch::whereKey($switch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'requested') {
                throw new ConflictException('This switch request was already decided.');
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

                Patient::whereKey($locked->patient_id)->update(['therapist_id' => $target->user_id]);
            }

            $locked->update(['status' => $approve ? 'approved' : 'rejected']);

            $this->audit->record($admin, AuditLogService::THERAPIST_SWITCH_DECIDED, $locked->id, [
                'approved' => $approve, 'note' => $note,
            ]);

            return $locked->refresh();
        });

        $this->notifications->therapistSwitchDecided($switch);

        return $switch;
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
            'requested_at' => $switch->timestamp?->toISOString(),
        ];
    }
}
