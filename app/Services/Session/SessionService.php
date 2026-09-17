<?php

namespace App\Services\Session;

use App\Enums\PaymentStatus;
use App\Enums\SessionMedium;
use App\Enums\SessionStatus;
use App\Models\Patient;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\NotificationService;
use App\Services\Therapist\TherapistService;
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
    ) {}

    /**
     * Book a session for a patient.
     *
     * Business rule: the first-ever session (is_initial) is free when the
     * patient holds an active approved subscription; otherwise the
     * configured session price applies and awaits payment proof.
     */
    public function book(Patient $patient, array $data): TherapySession
    {
        $therapist = $this->therapistService->getTherapist($data['therapist_id']);

        if (! $therapist->can_accept_new_clients) {
            throw ValidationException::withMessages(['therapist_id' => 'This therapist is not accepting new clients.']);
        }

        $date = Carbon::parse($data['session_date'])->startOfDay();

        if (! $this->therapistService->isSlotAvailable($therapist, $date, $data['session_time'])) {
            throw ValidationException::withMessages(['session_time' => 'The requested slot is not available.']);
        }

        if ($this->sessions->hasConflict($therapist->user_id, $date->toDateString(), $data['session_time'])) {
            throw ValidationException::withMessages(['session_time' => 'The requested slot was just taken.']);
        }

        $isInitial = $this->sessions->countNonCancelledForPatient($patient->user_id) === 0;
        $hasActiveSubscription = $this->subscriptions->activeForPatient($patient->user_id) !== null;
        $isFree = $isInitial && $hasActiveSubscription;

        return DB::transaction(function () use ($patient, $therapist, $data, $date, $isInitial, $isFree) {
            $session = $this->sessions->create([
                'patient_id' => $patient->user_id,
                'therapist_id' => $therapist->user_id,
                'session_date' => $date->toDateString(),
                'session_time' => $data['session_time'],
                'medium' => SessionMedium::from($data['medium'])->value,
                'price' => $isFree ? 0 : (float) config('sakina.session_price', 50.00),
                'status' => SessionStatus::PENDING->value,
                'is_initial' => $isInitial,
                'payment_status' => $isFree ? PaymentStatus::FREE->value : PaymentStatus::PENDING->value,
            ]);

            $this->logStatus($session, null, SessionStatus::PENDING, $patient->user_id);

            // Assign the therapist to the patient and keep counters honest.
            if ($patient->therapist_id !== $therapist->user_id) {
                $patient->update(['therapist_id' => $therapist->user_id]);
            }

            $this->syncClientsCount($therapist);

            $this->notifications->sessionBooked($session->fresh(['patient.user', 'therapist.user']));

            return $session;
        });
    }

    /**
     * Patient (or admin) cancels a pending/confirmed session — frees the slot.
     */
    public function cancel(TherapySession $session, User $actor): TherapySession
    {
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
     * Therapist marks a confirmed session completed, with a summary.
     */
    public function complete(TherapySession $session, User $actor, ?string $summary): TherapySession
    {
        $this->assertOwner($session, $actor);

        return $this->transition($session, SessionStatus::COMPLETED, $actor, [SessionStatus::CONFIRMED], [
            'summary' => $summary,
        ]);
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

        $this->sessions->update($session, ['link' => $link]);

        return $session->refresh();
    }

    /**
     * Internal transition used after payment approval: pending → confirmed.
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

    private function transition(TherapySession $session, SessionStatus $to, User $actor, array $allowedFrom, array $extra = []): TherapySession
    {
        if (! in_array($session->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => sprintf('Cannot move a %s session to %s.', $session->status->value, $to->value),
            ]);
        }

        return DB::transaction(function () use ($session, $to, $actor, $extra) {
            $from = $session->status;

            $this->sessions->update($session, ['status' => $to->value] + $extra);
            $this->logStatus($session, $from, $to, $actor->id);

            if ($to === SessionStatus::CANCELLED && $session->therapist) {
                $this->syncClientsCount($session->therapist);
            }

            $fresh = $session->fresh(['patient.user', 'therapist.user']);
            $this->notifications->sessionStatusChanged($fresh, $from, $to);

            return $fresh;
        });
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

    private function syncClientsCount(Therapist $therapist): void
    {
        $therapist->update([
            'clients_count' => $therapist->sessions()
                ->where('status', '!=', SessionStatus::CANCELLED->value)
                ->distinct('patient_id')
                ->count('patient_id'),
        ]);
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
            'created_at' => $session->created_at?->toISOString(),
        ];
    }
}
