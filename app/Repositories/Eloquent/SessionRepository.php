<?php

namespace App\Repositories\Eloquent;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\TherapySession;
use App\Repositories\Contracts\SessionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

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

    public function countNonCancelledForPatientInRange(string $patientId, string $from, string $to): int
    {
        return TherapySession::where('patient_id', $patientId)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->whereDate('session_date', '>=', $from)
            ->whereDate('session_date', '<=', $to)
            ->count();
    }

    public function hasConflict(string $therapistId, string $date, string $time): bool
    {
        $time = substr($time, 0, 5);

        foreach ($this->bookedTimesFor($therapistId, $date) as $booked) {
            if (TherapySession::startTimesOverlap($booked, $time)) {
                return true;
            }
        }

        return false;
    }

    public function hasActiveSessionAt(string $patientId, string $date, string $time): bool
    {
        $time = substr($time, 0, 5);

        return TherapySession::where('patient_id', $patientId)
            ->whereDate('session_date', $date)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->pluck('session_time')
            ->contains(fn ($booked) => TherapySession::startTimesOverlap(substr((string) $booked, 0, 5), $time));
    }

    public function countNonCancelledBetween(string $patientId, string $therapistId): int
    {
        return TherapySession::where('patient_id', $patientId)
            ->where('therapist_id', $therapistId)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->count();
    }

    public function hasUsedInitialSession(string $patientId): bool
    {
        return TherapySession::where('patient_id', $patientId)
            ->where(fn ($q) => $q
                ->where('status', '!=', SessionStatus::CANCELLED->value)
                ->orWhere('payment_status', PaymentStatus::FREE->value))
            ->exists();
    }

    public function dueForReminder(Carbon $from, Carbon $to, string $flag): Collection
    {
        return TherapySession::with(['patient.user', 'therapist.user'])
            ->where('status', SessionStatus::CONFIRMED->value)
            ->where($flag, false)
            ->whereDate('session_date', '>=', $from->toDateString())
            ->whereDate('session_date', '<=', $to->toDateString())
            ->get()
            ->filter(function (TherapySession $session) use ($from, $to) {
                $startsAt = Carbon::parse($session->session_date->toDateString().' '.substr((string) $session->session_time, 0, 5));

                return $startsAt->between($from, $to);
            })
            ->values();
    }

    public function activeSessionsForDate(string $therapistId, string $date): Collection
    {
        return TherapySession::where('therapist_id', $therapistId)
            ->whereDate('session_date', $date)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->get();
    }
}
