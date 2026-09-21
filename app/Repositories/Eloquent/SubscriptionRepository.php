<?php

namespace App\Repositories\Eloquent;

use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function findById(string $id): ?Subscription
    {
        return Subscription::find($id);
    }

    public function create(array $data): Subscription
    {
        return Subscription::create($data);
    }

    public function update(Subscription $subscription, array $data): bool
    {
        return $subscription->update($data);
    }

    public function activeForPatient(string $patientId): ?Subscription
    {
        return Subscription::where('patient_id', $patientId)
            ->where('verification_status', 'approved')
            ->whereNull('cancelled_at')
            ->whereNotNull('end_date')
            ->where('end_date', '>=', now()->toDateString())
            ->latest('end_date')
            ->first();
    }

    public function hasPendingOrActive(string $patientId): bool
    {
        return Subscription::where('patient_id', $patientId)
            ->whereNull('cancelled_at')
            ->where(function ($q) {
                $q->where('verification_status', 'pending')
                    ->orWhere(function ($q2) {
                        $q2->where('verification_status', 'approved')
                            ->where('end_date', '>=', now()->toDateString());
                    });
            })
            ->exists();
    }

    public function forPatient(string $patientId): Collection
    {
        return Subscription::where('patient_id', $patientId)
            ->orderByDesc('created_at')
            ->get();
    }
}
