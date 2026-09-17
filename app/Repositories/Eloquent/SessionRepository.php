<?php

namespace App\Repositories\Eloquent;

use App\Enums\SessionStatus;
use App\Models\TherapySession;
use App\Repositories\Contracts\SessionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class SessionRepository implements SessionRepositoryInterface
{
    public function findById(string $id): ?TherapySession
    {
        return TherapySession::find($id);
    }

    public function create(array $data): TherapySession
    {
        return TherapySession::create($data);
    }

    public function update(TherapySession $session, array $data): bool
    {
        return $session->update($data);
    }

    public function forPatient(string $patientId, int $perPage): LengthAwarePaginator
    {
        return TherapySession::where('patient_id', $patientId)
            ->orderByDesc('session_date')
            ->orderByDesc('session_time')
            ->paginate($perPage);
    }

    public function forTherapist(string $therapistId, int $perPage): LengthAwarePaginator
    {
        return TherapySession::where('therapist_id', $therapistId)
            ->orderBy('session_date')
            ->orderBy('session_time')
            ->paginate($perPage);
    }

    public function bookedTimesFor(string $therapistId, string $date): array
    {
        return TherapySession::where('therapist_id', $therapistId)
            ->whereDate('session_date', $date)
            ->whereIn('status', [
                SessionStatus::PENDING->value,
                SessionStatus::CONFIRMED->value,
            ])
            ->pluck('session_time')
            ->map(fn ($t) => substr((string) $t, 0, 5))
            ->all();
    }

    public function countNonCancelledForPatient(string $patientId): int
    {
        return TherapySession::where('patient_id', $patientId)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->count();
    }

    public function hasConflict(string $therapistId, string $date, string $time): bool
    {
        return in_array(substr($time, 0, 5), $this->bookedTimesFor($therapistId, $date), true);
    }

    public function activeSessionsForDate(string $therapistId, string $date): Collection
    {
        return TherapySession::where('therapist_id', $therapistId)
            ->whereDate('session_date', $date)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->get();
    }
}
