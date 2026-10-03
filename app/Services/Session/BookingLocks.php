<?php

namespace App\Services\Session;

use App\Exceptions\ConflictException;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapySession;
use Illuminate\Database\Eloquent\Collection;

/**
 * Transaction lock order: patient -> subscriptions (id) -> therapists (user_id)
 * -> switch -> sessions (id) -> payments. Never acquire an earlier lock later.
 * The patient lock serializes package/assignment/schedule changes; therapist
 * locks serialize capacity and slots across patients. Call inside a transaction.
 */
final class BookingLocks
{
    public static function patient(string $id): Patient
    {
        return Patient::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public static function subscriptions(Patient $patient): Collection
    {
        return Subscription::where('patient_id', $patient->user_id)->orderBy('id')->lockForUpdate()->get();
    }

    public static function therapists(array $ids): Collection
    {
        return Therapist::whereIn('user_id', array_filter($ids))->orderBy('user_id')->lockForUpdate()->get();
    }

    public static function session(string $id): TherapySession
    {
        $snapshot = TherapySession::findOrFail($id);
        $patient = self::patient($snapshot->patient_id);
        self::subscriptions($patient);
        self::therapists([$snapshot->therapist_id]);
        $locked = TherapySession::whereKey($id)->lockForUpdate()->firstOrFail();

        if ($locked->patient_id !== $snapshot->patient_id || $locked->therapist_id !== $snapshot->therapist_id) {
            throw new ConflictException('The session assignment changed; retry the request.');
        }

        return $locked;
    }
}
