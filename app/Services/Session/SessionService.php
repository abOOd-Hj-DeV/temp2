<?php

namespace App\Services\Session;

use App\Enums\PaymentStatus;
use App\Enums\SessionMedium;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Exceptions\ConflictException;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\Therapist\TherapistService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionService
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SubscriptionRepositoryInterface $subscriptions,
        private TherapistService $therapistService,
        private NotificationService $notifications,
        private AuditLogService $audit,
    ) {}

    /**
     * Book a session for a patient.
     *
     * Business rule: the first-ever session (is_initial) is always free, once.
     * Afterwards a session booked under an active package is covered by the
     * package price (price 0, no payment proof) within the package quota;
     * without a package the configured single-session price applies and
     * awaits payment proof.
     *
     * Every check runs inside the transaction while holding a row lock on
     * the therapist, and the partial unique index on
     * (therapist_id, session_date, session_time) is the final arbiter.
     */
    public function book(Patient $patient, array $data): TherapySession
    {
        $therapist = $this->therapistService->getTherapist($data['therapist_id']);
        $date = Carbon::parse($data['session_date'])->startOfDay();
        $time = substr($data['session_time'], 0, 5);

        try {
            $session = DB::transaction(function () use ($patient, $therapist, $data, $date, $time) {
                $therapist = Therapist::whereKey($therapist->user_id)->lockForUpdate()->firstOrFail();
                // Re-read the assignment under lock so a concurrent switch or
                // booking cannot race it; the caller's instance stays in sync.
                $patient->setRawAttributes(
                    Patient::whereKey($patient->user_id)->lockForUpdate()->firstOrFail()->getAttributes(),
                    true
                );

                $this->assertBookableTherapist($patient, $therapist);

                if (! $this->therapistService->isSlotAvailable($therapist, $date, $time)) {
                    throw ValidationException::withMessages(['session_time' => 'The requested slot is not available.']);
                }

                if ($this->sessions->hasConflict($therapist->user_id, $date->toDateString(), $time)) {
                    throw ValidationException::withMessages(['session_time' => 'The requested slot was just taken.']);
                }

                if ($this->sessions->hasActiveSessionAt($patient->user_id, $date->toDateString(), $time)) {
                    throw ValidationException::withMessages(['session_time' => 'You already have a session at this time.']);
                }

                $isExistingClient = $this->sessions->countNonCancelledBetween($patient->user_id, $therapist->user_id) > 0;
                $distinctClients = $this->distinctClients($therapist);

                if (! $isExistingClient && $distinctClients >= $therapist->clients_limit) {
                    throw ValidationException::withMessages(['therapist_id' => 'This therapist is not accepting new clients.']);
                }

                $isInitial = ! $this->sessions->hasUsedInitialSession($patient->user_id);
                $subscription = $this->subscriptions->activeForPatient($patient->user_id);
                $coveringSubscription = null;

                if ($subscription !== null) {
                    $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
                    $this->assertWithinPackageQuota($patient, $subscription, $date);

                    if (! $isInitial) {
                        $coveringSubscription = $subscription;
                    }
                }

                $isFree = $isInitial || $coveringSubscription !== null;

                $session = $this->sessions->create([
                    'patient_id' => $patient->user_id,
                    'therapist_id' => $therapist->user_id,
                    'session_date' => $date->toDateString(),
                    'session_time' => $time,
                    'medium' => SessionMedium::from($data['medium'])->value,
                    'price' => $isFree ? 0 : (float) config('sakina.session_price', 50.00),
                    'status' => SessionStatus::PENDING->value,
                    'is_initial' => $isInitial,
                    'payment_status' => $isFree ? PaymentStatus::FREE->value : PaymentStatus::PENDING->value,
                    'subscription_id' => $coveringSubscription?->id,
                ]);

                if ($coveringSubscription !== null && $coveringSubscription->therapist_id === null) {
                    $coveringSubscription->update(['therapist_id' => $therapist->user_id]);
                }

                $this->logStatus($session, null, SessionStatus::PENDING, $patient->user_id);

                if ($patient->therapist_id === null) {
                    $patient->update(['therapist_id' => $therapist->user_id]);
                }

                $therapist->update(['clients_count' => $isExistingClient ? $distinctClients : $distinctClients + 1]);

                $this->audit->record($patient->user_id, AuditLogService::SESSION_BOOKED, $session->id, [
                    'therapist_id' => $therapist->user_id,
                    'date' => $date->toDateString(),
                    'time' => $time,
                    'price' => $session->price,
                    'subscription_id' => $session->subscription_id,
                ]);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException('This slot was just booked by someone else.');
        }

        $this->notifications->deliver('sessionBooked', $session->fresh(['patient.user', 'therapist.user']));

        return $session;
    }

    /**
     * Patient/therapist/admin cancels a pending/confirmed session — frees
     * the slot. Patients must respect the cancellation notice window.
     */
    public function cancel(TherapySession $session, User $actor): TherapySession
    {
        if ($actor->role === UserRole::PATIENT) {
            $this->assertCancellable($session);
        }

        return $this->transition($session, SessionStatus::CANCELLED, $actor, [
            SessionStatus::PENDING,
            SessionStatus::CONFIRMED,
        ], [
            'reschedule_date' => null,
            'reschedule_time' => null,
            'reschedule_requested_by' => null,
            'reschedule_requested_at' => null,
        ]);
    }

    /**
     * Therapist confirms a pending paid/free session.
     */
    public function confirm(TherapySession $session, User $actor): TherapySession
    {
        $this->assertOwner($session, $actor);

        if ($session->payment_status === PaymentStatus::PENDING) {
            throw ValidationException::withMessages(['payment_status' => 'Payment proof must be approved before confirming.']);
        }

        return $this->transition($session, SessionStatus::CONFIRMED, $actor, [SessionStatus::PENDING]);
    }

    /**
     * Therapist marks a confirmed session completed, with a summary. Only
     * allowed once the scheduled start time has passed.
     */
    public function complete(TherapySession $session, User $actor, ?string $summary): TherapySession
    {
        $this->assertOwner($session, $actor);

        if ($this->startsAt($session)->isFuture()) {
            throw ValidationException::withMessages(['status' => 'A session cannot be completed before it starts.']);
        }

        if ($session->attendance_confirmed_at === null) {
            throw ValidationException::withMessages([
                'attendance' => 'The patient (or a supervisor) must confirm attendance before the session can be completed.',
            ]);
        }

        return $this->transition($session, SessionStatus::COMPLETED, $actor, [SessionStatus::CONFIRMED], [
            'summary' => $summary,
        ]);
    }

    /**
     * The patient (or, in a dispute, a clinical supervisor/admin) confirms the
     * session actually took place. Completion — and therefore therapist
     * earnings — is impossible without it.
     */
    public function confirmAttendance(TherapySession $session, User $actor): TherapySession
    {
        $isStaff = in_array($actor->role, [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::CLINICAL_SUPERVISOR], true);

        if (! $isStaff && $actor->id !== $session->patient_id) {
            throw new AuthorizationException('Only the patient of this session can confirm attendance.');
        }

        if ($this->startsAt($session)->isFuture()) {
            throw ValidationException::withMessages(['attendance' => 'Attendance can only be confirmed after the session starts.']);
        }

        return DB::transaction(function () use ($session, $actor, $isStaff) {
            $locked = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SessionStatus::CONFIRMED) {
                throw new ConflictException(sprintf('Attendance cannot be confirmed for a %s session.', $locked->status->value));
            }

            if ($locked->attendance_confirmed_at !== null) {
                throw new ConflictException('Attendance was already confirmed.');
            }

            $this->sessions->update($locked, ['attendance_confirmed_at' => now()]);

            $this->audit->record($actor, AuditLogService::SESSION_ATTENDANCE_CONFIRMED, $locked->id, [
                'by_staff' => $isStaff,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Therapist writes the post-session report. Completes the session when
     * it is still confirmed; otherwise records an audited revision.
     */
    public function report(TherapySession $session, User $actor, string $summary): TherapySession
    {
        $this->assertOwner($session, $actor);

        if ($session->status === SessionStatus::CONFIRMED) {
            return $this->complete($session, $actor, $summary);
        }

        if ($session->status !== SessionStatus::COMPLETED) {
            throw ValidationException::withMessages(['status' => 'Reports can only be written for confirmed or completed sessions.']);
        }

        return DB::transaction(function () use ($session, $actor, $summary) {
            $locked = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $revision = $locked->report_revision + 1;

            $this->sessions->update($locked, ['summary' => $summary, 'report_revision' => $revision]);

            $this->audit->record($actor, AuditLogService::SESSION_REPORT_REVISED, $locked->id, [
                'revision' => $revision,
                'previous_sha256' => $locked->summary === null ? null : hash('sha256', $locked->summary),
                'new_sha256' => hash('sha256', $summary),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Patient asks to move a pending/confirmed session. Nothing changes until
     * the therapist approves; the current slot stays reserved meanwhile.
     */
    public function requestReschedule(TherapySession $session, User $actor, string $date, string $time): TherapySession
    {
        if ($actor->id !== $session->patient_id) {
            throw new AuthorizationException('Only the patient of this session can request a reschedule.');
        }

        $this->assertCancellable($session);

        $date = Carbon::parse($date)->startOfDay();
        $time = substr($time, 0, 5);

        if ($date->isPast() && ! $date->isToday()) {
            throw ValidationException::withMessages(['session_date' => 'The new date must be in the future.']);
        }

        $fresh = DB::transaction(function () use ($session, $actor, $date, $time) {
            $locked = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [SessionStatus::PENDING, SessionStatus::CONFIRMED], true)) {
                throw new ConflictException(sprintf('A %s session cannot be rescheduled.', $locked->status->value));
            }

            if ($locked->reschedule_date !== null) {
                throw new ConflictException('A reschedule request is already awaiting the therapist.');
            }

            $therapist = Therapist::whereKey($locked->therapist_id)->firstOrFail();

            if (! $this->therapistService->isSlotAvailable($therapist, $date, $time, $locked->id)) {
                throw ValidationException::withMessages(['session_time' => 'The requested slot is not available.']);
            }

            if ($this->sessions->hasConflict($therapist->user_id, $date->toDateString(), $time, $locked->id)) {
                throw ValidationException::withMessages(['session_time' => 'The requested slot is already taken.']);
            }

            $this->sessions->update($locked, [
                'reschedule_date' => $date->toDateString(),
                'reschedule_time' => $time,
                'reschedule_requested_by' => $actor->id,
                'reschedule_requested_at' => now(),
            ]);

            $this->audit->record($actor, AuditLogService::SESSION_RESCHEDULE_REQUESTED, $locked->id, [
                'to_date' => $date->toDateString(), 'to_time' => $time,
            ]);

            return $locked->fresh(['patient.user', 'therapist.user']);
        });

        $this->notifications->deliver('sessionRescheduleRequested', $fresh, $fresh->therapist->user);

        return $fresh;
    }

    /**
     * Therapist approves (moves the session under the therapist row lock; the
     * active-slot unique index is the final arbiter) or rejects the request.
     */
    public function decideReschedule(TherapySession $session, User $actor, bool $approve): TherapySession
    {
        $this->assertOwner($session, $actor);

        try {
            $fresh = DB::transaction(function () use ($session, $actor, $approve) {
                Therapist::whereKey($session->therapist_id)->lockForUpdate()->firstOrFail();
                $locked = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();

                if ($locked->reschedule_date === null) {
                    throw new ConflictException('There is no pending reschedule request for this session.');
                }

                if (! in_array($locked->status, [SessionStatus::PENDING, SessionStatus::CONFIRMED], true)) {
                    throw new ConflictException(sprintf('A %s session cannot be rescheduled.', $locked->status->value));
                }

                $newDate = $locked->reschedule_date->toDateString();
                $newTime = substr((string) $locked->reschedule_time, 0, 5);
                $clear = [
                    'reschedule_date' => null, 'reschedule_time' => null,
                    'reschedule_requested_by' => null, 'reschedule_requested_at' => null,
                ];

                if ($approve) {
                    if ($this->sessions->hasConflict($locked->therapist_id, $newDate, $newTime, $locked->id)) {
                        throw ValidationException::withMessages(['session_time' => 'The requested slot is no longer free.']);
                    }

                    $this->sessions->update($locked, $clear + [
                        'session_date' => $newDate,
                        'session_time' => $newTime,
                        'reminder_sent' => false,
                        'reminder_1h_sent' => false,
                    ]);
                } else {
                    $this->sessions->update($locked, $clear);
                }

                $this->audit->record($actor, AuditLogService::SESSION_RESCHEDULE_DECIDED, $locked->id, [
                    'approved' => $approve, 'to_date' => $newDate, 'to_time' => $newTime,
                ]);

                return $locked->fresh(['patient.user', 'therapist.user']);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException('The requested slot was just booked by someone else.');
        }

        $this->notifications->deliver('sessionRescheduleDecided', $fresh, $approve);

        return $fresh;
    }

    /**
     * Therapist attaches the meeting link (zoom/meet/whatsapp).
     */
    public function setLink(TherapySession $session, User $actor, string $link): TherapySession
    {
        $this->assertOwner($session, $actor);

        if (in_array($session->status, [SessionStatus::CANCELLED, SessionStatus::COMPLETED], true)) {
            throw ValidationException::withMessages(['status' => 'Cannot set a link on a closed session.']);
        }

        $this->assertLinkMatchesMedium($session, $link);

        $this->sessions->update($session, ['link' => $link]);

        return $session->refresh();
    }

    /**
     * Internal transition used after payment approval: pending → confirmed.
     * Caller is expected to hold the payment lock.
     */
    public function markPaidAndConfirmed(TherapySession $session, User $actor): TherapySession
    {
        $this->sessions->update($session, ['payment_status' => PaymentStatus::PAID->value]);
        $session->refresh();

        return $this->transition($session, SessionStatus::CONFIRMED, $actor, [SessionStatus::PENDING]);
    }

    public function forPatient(string $patientId, int $perPage): LengthAwarePaginator
    {
        return $this->sessions->forPatient($patientId, max(1, min($perPage, 50)));
    }

    public function forTherapist(string $therapistId, int $perPage): LengthAwarePaginator
    {
        return $this->sessions->forTherapist($therapistId, max(1, min($perPage, 50)));
    }

    public function statusLog(TherapySession $session): array
    {
        return $session->statusLogs()->with('actor:id,name')->orderBy('created_at')->get()->all();
    }

    public function startsAt(TherapySession $session): Carbon
    {
        return Carbon::parse($session->session_date->toDateString().' '.substr((string) $session->session_time, 0, 5));
    }

    /**
     * State-machine transition guarded by a row lock so two concurrent
     * actors can never both succeed on the same session.
     */
    private function transition(TherapySession $session, SessionStatus $to, User $actor, array $allowedFrom, array $extra = []): TherapySession
    {
        if (! in_array($session->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => sprintf('Cannot move a %s session to %s.', $session->status->value, $to->value),
            ]);
        }

        [$fresh, $from] = DB::transaction(function () use ($session, $to, $actor, $allowedFrom, $extra) {
            $locked = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $allowedFrom, true)) {
                throw new ConflictException(sprintf('Session is already %s.', $locked->status->value));
            }

            $from = $locked->status;

            $this->sessions->update($locked, ['status' => $to->value] + $extra);
            $this->logStatus($locked, $from, $to, $actor->id);

            if ($to === SessionStatus::CANCELLED && $locked->therapist) {
                $this->syncClientsCount($locked->therapist);
            }

            $this->audit->record($actor, AuditLogService::SESSION_TRANSITIONED, $locked->id, [
                'from' => $from->value,
                'to' => $to->value,
            ]);

            return [$locked->fresh(['patient.user', 'therapist.user']), $from];
        });

        $this->notifications->deliver('sessionStatusChanged', $fresh, $from, $to);

        return $fresh;
    }

    private function logStatus(TherapySession $session, ?SessionStatus $from, SessionStatus $to, string $actorId): void
    {
        $session->statusLogs()->create([
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actorId,
        ]);
    }

    /**
     * Package limits frozen on the subscription at purchase: total sessions
     * over its term and sessions per calendar day.
     */
    private function assertWithinPackageQuota(Patient $patient, Subscription $subscription, Carbon $date): void
    {
        $from = $subscription->start_date?->toDateString() ?? $date->toDateString();
        $to = $subscription->end_date?->toDateString() ?? $date->toDateString();

        if ($subscription->sessions_total !== null
            && $this->sessions->countNonCancelledForPatientInRange($patient->user_id, $from, $to) >= $subscription->sessions_total) {
            throw ValidationException::withMessages([
                'subscription' => "Your package's {$subscription->sessions_total} sessions are all booked.",
            ]);
        }

        $daily = $subscription->daily_sessions_quota ?? 1;
        $day = $date->toDateString();

        if ($this->sessions->countNonCancelledForPatientInRange($patient->user_id, $day, $day) >= $daily) {
            throw ValidationException::withMessages([
                'session_date' => "Your package allows {$daily} session(s) per day.",
            ]);
        }
    }

    /**
     * The first booking assigns the therapist; afterwards the assignment only
     * changes through the reviewed therapist-switch workflow, never as a side
     * effect of booking with someone else.
     */
    private function assertBookableTherapist(Patient $patient, Therapist $therapist): void
    {
        if ($patient->therapist_id !== null && $patient->therapist_id !== $therapist->user_id) {
            throw ValidationException::withMessages([
                'therapist_id' => 'Sessions can only be booked with your assigned therapist. Request a therapist switch to change it.',
            ]);
        }
    }

    private function assertOwner(TherapySession $session, User $actor): void
    {
        if ($actor->id !== $session->therapist_id) {
            throw ValidationException::withMessages(['session' => 'This session belongs to another therapist.']);
        }
    }

    private function assertCancellable(TherapySession $session): void
    {
        $noticeHours = (int) config('sakina.cancellation_notice_hours', 24);

        if ($this->startsAt($session)->subHours($noticeHours)->isPast()) {
            throw ValidationException::withMessages([
                'status' => "Sessions can only be cancelled at least {$noticeHours} hours before they start.",
            ]);
        }
    }

    private function assertLinkMatchesMedium(TherapySession $session, string $link): void
    {
        $allowed = config('sakina.meeting_link_hosts', [])[$session->medium?->value] ?? [];
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));

        $matches = $scheme === 'https' && collect($allowed)->contains(
            fn (string $allowedHost) => $host === $allowedHost || str_ends_with($host, '.'.$allowedHost)
        );

        if (! $matches) {
            throw ValidationException::withMessages([
                'link' => 'The link must be an https URL on a host allowed for this session medium.',
            ]);
        }
    }

    private function distinctClients(Therapist $therapist): int
    {
        return $therapist->sessions()
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->distinct('patient_id')
            ->count('patient_id');
    }

    private function syncClientsCount(Therapist $therapist): void
    {
        $therapist->update(['clients_count' => $this->distinctClients($therapist)]);
    }

    public function toArray(TherapySession $session): array
    {
        return [
            'id' => $session->id,
            'patient_id' => $session->patient_id,
            'therapist_id' => $session->therapist_id,
            'therapist_name' => $session->therapist?->full_name,
            'session_date' => $session->session_date?->toDateString(),
            'session_time' => $session->session_time,
            'medium' => $session->medium?->value,
            'price' => $session->price,
            'status' => $session->status?->value,
            'is_initial' => $session->is_initial,
            'payment_status' => $session->payment_status?->value,
            'subscription_id' => $session->subscription_id,
            'link' => $session->link,
            'summary' => $session->summary,
            'report_revision' => $session->report_revision,
            'attendance_confirmed_at' => $session->attendance_confirmed_at?->toISOString(),
            'reschedule' => $session->reschedule_date === null ? null : [
                'date' => $session->reschedule_date->toDateString(),
                'time' => substr((string) $session->reschedule_time, 0, 5),
                'requested_at' => $session->reschedule_requested_at?->toISOString(),
            ],
            'created_at' => $session->created_at?->toISOString(),
        ];
    }
}
