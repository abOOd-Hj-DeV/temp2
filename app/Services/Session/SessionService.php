<?php

namespace App\Services\Session;

use App\Enums\PaymentStatus;
use App\Enums\SessionMedium;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Exceptions\ConflictException;
use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\Therapist\TherapistService;
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
     * Business rule: the first-ever session (is_initial) is free when the
     * patient holds an active approved subscription; otherwise the
     * configured session price applies and awaits payment proof.
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
                $hasActiveSubscription = $this->subscriptions->activeForPatient($patient->user_id) !== null;
                $isFree = $isInitial && $hasActiveSubscription;

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
                ]);

                $this->logStatus($session, null, SessionStatus::PENDING, $patient->user_id);

                if ($patient->therapist_id !== $therapist->user_id) {
                    $patient->update(['therapist_id' => $therapist->user_id]);
                }

                $therapist->update(['clients_count' => $isExistingClient ? $distinctClients : $distinctClients + 1]);

                $this->audit->record($patient->user_id, AuditLogService::SESSION_BOOKED, $session->id, [
                    'therapist_id' => $therapist->user_id,
                    'date' => $date->toDateString(),
                    'time' => $time,
                    'price' => $session->price,
                ]);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException('This slot was just booked by someone else.');
        }

        $this->notifications->sessionBooked($session->fresh(['patient.user', 'therapist.user']));

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

        return $this->transition($session, SessionStatus::COMPLETED, $actor, [SessionStatus::CONFIRMED], [
            'summary' => $summary,
        ]);
    }

    /**
     * Therapist writes the post-session report. Completes the session when
     * it is still confirmed; otherwise just updates the summary.
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

        $this->sessions->update($session, ['summary' => $summary]);

        return $session->refresh();
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

        $this->notifications->sessionStatusChanged($fresh, $from, $to);

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
            'link' => $session->link,
            'summary' => $session->summary,
            'created_at' => $session->created_at?->toISOString(),
        ];
    }
}
