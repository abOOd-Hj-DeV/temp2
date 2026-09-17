<?php

namespace App\Repositories\Contracts;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

interface SubscriptionRepositoryInterface
{
    public function findById(string $id): ?Subscription;

    public function create(array $data): Subscription;

    public function update(Subscription $subscription, array $data): bool;

    /**
     * The patient's currently active subscription (approved, within dates).
     */
    public function activeForPatient(string $patientId): ?Subscription;

    /**
     * Whether the patient already has an approved or pending subscription.
     */
    public function hasPendingOrActive(string $patientId): bool;

    /**
     * @return Collection<int, Subscription>
     */
    public function forPatient(string $patientId): Collection;
}
