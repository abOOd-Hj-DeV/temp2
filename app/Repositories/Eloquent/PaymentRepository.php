<?php

namespace App\Repositories\Eloquent;

use App\Enums\PaymentReviewStatus;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function findById(string $id): ?Payment
    {
        return Payment::find($id);
    }

    public function create(array $data): Payment
    {
        return Payment::create($data);
    }

    public function update(Payment $payment, array $data): bool
    {
        return $payment->update($data);
    }

    public function pendingPaginated(int $perPage): LengthAwarePaginator
    {
        return Payment::where('status', PaymentReviewStatus::PENDING->value)
            ->with(['subscription.patient.user', 'session.patient.user', 'session.therapist'])
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    public function hasOpenPaymentForSession(string $sessionId): bool
    {
        return Payment::where('therapy_session_id', $sessionId)
            ->whereIn('status', [
                PaymentReviewStatus::PENDING->value,
                PaymentReviewStatus::APPROVED->value,
            ])
            ->exists();
    }

    public function hasOpenPaymentForSubscription(string $subscriptionId): bool
    {
        return Payment::where('subscription_id', $subscriptionId)
            ->whereIn('status', [
                PaymentReviewStatus::PENDING->value,
                PaymentReviewStatus::APPROVED->value,
            ])
            ->exists();
    }
}
