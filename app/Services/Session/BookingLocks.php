<?php

namespace App\Services\Session;

use App\Exceptions\ConflictException;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Services\Files\AccountFileFence;
use Illuminate\Database\Eloquent\Collection;

/**
 * Transaction lock order: patient user -> patient -> subscriptions (id) ->
 * therapist users (id) -> therapists (user_id) -> switch -> sessions (id)
 * -> payments. Never acquire an earlier lock later.
 * The patient lock serializes package/assignment/schedule changes; therapist
 * locks serialize capacity and slots across patients. Call inside a transaction.
 */
final class BookingLocks
{
    public static function patient(string $id, bool $requireAvailable = false): Patient
    {
        if ($requireAvailable) {
            AccountFileFence::lock([$id]);
        } else {
            User::whereKey($id)->lockForUpdate()->firstOrFail();
        }

        return Patient::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public static function subscriptions(Patient $patient): Collection
    {
        return Subscription::where('patient_id', $patient->user_id)->orderBy('id')->lockForUpdate()->get();
    }

    public static function therapists(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        $users = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $therapists = Therapist::whereIn('user_id', $ids)->orderBy('user_id')->lockForUpdate()->get();
        foreach ($therapists as $therapist) {
            $therapist->setRelation('user', $users->get($therapist->user_id));
        }

        return $therapists;
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
