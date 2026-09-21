<?php

namespace App\Repositories\Contracts;

use App\Models\TherapySession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

interface SessionRepositoryInterface
{
    public function findById(string $id): ?TherapySession;

    public function create(array $data): TherapySession;

    public function update(TherapySession $session, array $data): bool;

    public function forPatient(string $patientId, int $perPage): LengthAwarePaginator;

    public function forTherapist(string $therapistId, int $perPage): LengthAwarePaginator;

    /**
     * Start times already taken by non-cancelled sessions for a therapist/date.
     *
     * @return array<string> times as H:i:s
     */
    public function bookedTimesFor(string $therapistId, string $date, ?string $excludeSessionId = null): array;

    /**
     * UTC start instants of pending/confirmed sessions for a therapist whose
     * stored date falls within [$from, $to] (inclusive, UTC dates).
     *
     * @return array<int, Carbon>
     */
    public function bookedStartsBetween(string $therapistId, Carbon $from, Carbon $to, ?string $excludeSessionId = null): array;

    /**
     * Count of sessions a patient has that were never cancelled — used to
     * derive is_initial (the first real booking).
     */
    public function countNonCancelledForPatient(string $patientId): int;

    /** Non-cancelled sessions of a patient dated within [$from, $to] (inclusive). */
    public function countNonCancelledForPatientInRange(string $patientId, string $from, string $to): int;

    /**
     * Whether a pending/confirmed session of the therapist overlaps the
     * interval [time, time + session duration). Adjacent sessions do not
     * conflict.
     */
    public function hasConflict(string $therapistId, string $date, string $time, ?string $excludeSessionId = null): bool;

    /** Patient already holds a non-cancelled session overlapping this date/time interval. */
    public function hasActiveSessionAt(string $patientId, string $date, string $time): bool;

    /** Non-cancelled sessions between a patient and a therapist. */
    public function countNonCancelledBetween(string $patientId, string $therapistId): int;

    /**
     * Whether the patient has already consumed their initial session: any
     * session that was not cancelled. A cancelled trial is not consumed.
     */
    public function hasUsedInitialSession(string $patientId): bool;

    /** Non-cancelled sessions charged to a package (its quota usage). */
    public function countNonCancelledForSubscription(string $subscriptionId): int;

    /** Non-cancelled sessions charged to a package starting within [$from, $to) (UTC instants). */
    public function countNonCancelledForSubscriptionBetween(string $subscriptionId, Carbon $from, Carbon $to): int;

    /**
     * Cancel every pending/confirmed session charged to a package (called
     * inside the package-cancellation transaction). Returns the affected rows
     * as they were before the update.
     */
    public function cancelOpenForSubscription(string $subscriptionId): Collection;

    /** Confirmed sessions starting within the given window that still need a reminder. */
    public function dueForReminder(Carbon $from, Carbon $to, string $flag): Collection;

    /**
     * Non-cancelled sessions for a therapist on a given date.
     *
     * @return Collection<int, TherapySession>
     */
    public function activeSessionsForDate(string $therapistId, string $date): Collection;
}
