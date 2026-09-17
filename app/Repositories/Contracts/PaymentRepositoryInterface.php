<?php

namespace App\Repositories\Contracts;

use App\Models\Payment;
use Illuminate\Pagination\LengthAwarePaginator;

interface PaymentRepositoryInterface
{
    public function findById(string $id): ?Payment;

    public function create(array $data): Payment;

    public function update(Payment $payment, array $data): bool;

    public function pendingPaginated(int $perPage): LengthAwarePaginator;

    /**
     * Whether this session already has a payment under review or approved.
     */
    public function hasOpenPaymentForSession(string $sessionId): bool;

    /**
     * Whether this subscription already has a payment under review or approved.
     */
    public function hasOpenPaymentForSubscription(string $subscriptionId): bool;
}
