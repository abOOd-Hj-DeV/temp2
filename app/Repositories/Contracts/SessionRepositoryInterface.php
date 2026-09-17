<?php

namespace App\Repositories\Contracts;

use App\Models\TherapySession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

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
    public function bookedTimesFor(string $therapistId, string $date): array;

    /**
     * Count of sessions a patient has that were never cancelled — used to
     * derive is_initial (the first real booking).
     */
    public function countNonCancelledForPatient(string $patientId): int;

    /**
     * Whether the therapist has a non-cancelled session at this exact slot.
     */
    public function hasConflict(string $therapistId, string $date, string $time): bool;

    /**
     * Non-cancelled sessions for a therapist on a given date.
     *
     * @return Collection<int, TherapySession>
     */
    public function activeSessionsForDate(string $therapistId, string $date): Collection;
}
