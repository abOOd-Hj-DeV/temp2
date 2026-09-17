<?php

namespace App\Services\Billing;

use App\Enums\PaymentReviewStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionType;
use App\Jobs\ReviewPaymentProofJob;
use App\Models\Payment;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\NotificationService;
use App\Services\Session\SessionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentReviewService
{
    public function __construct(
        private PaymentRepositoryInterface $payments,
        private SubscriptionRepositoryInterface $subscriptions,
        private SessionService $sessionService,
        private NotificationService $notifications,
    ) {}

    /**
     * Persist a payment row and dispatch the queue job that alerts staff
     * to review the proof manually.
     */
    public function createPayment(float $amount, string $proofPath, ?string $subscriptionId = null, ?string $sessionId = null): Payment
    {
        $payment = $this->payments->create([
            'subscription_id' => $subscriptionId,
            'therapy_session_id' => $sessionId,
            'amount' => $amount,
            'proof_file_path' => $proofPath,
            'status' => PaymentReviewStatus::PENDING->value,
        ]);

        ReviewPaymentProofJob::dispatch($payment->id);

        return $payment;
    }

    /**
     * A patient uploads proof for a payable (non-free, unpaid) session.
     */
    public function submitSessionProof(TherapySession $session, UploadedFile $proof): Payment
    {
        if ($session->payment_status !== PaymentStatus::PENDING) {
            throw ValidationException::withMessages([
                'payment_status' => 'This session does not require a payment proof.',
            ]);
        }

        if ($this->payments->hasOpenPaymentForSession($session->id)) {
            throw ValidationException::withMessages([
                'payment' => 'A payment for this session is already under review.',
            ]);
        }

        $path = $proof->store(
            "payment-proofs/{$session->patient_id}",
            ['disk' => config('sakina.uploads_disk', 'local')]
        );

        return $this->createPayment(
            amount: (float) $session->price,
            proofPath: $path,
            sessionId: $session->id,
        );
    }

    /**
     * Staff decision on a submitted proof.
     *   approve → payment approved; subscription activated OR session paid+confirmed.
     *   reject  → payment rejected; the patient may upload a new proof.
     */
    public function review(Payment $payment, User $reviewer, string $action, ?string $note): Payment
    {
        if ($payment->status !== PaymentReviewStatus::PENDING) {
            throw ValidationException::withMessages(['payment' => 'This payment was already reviewed.']);
        }

        $approve = $action === 'approve';

        DB::transaction(function () use ($payment, $reviewer, $approve, $note) {
            $this->payments->update($payment, [
                'status' => $approve ? PaymentReviewStatus::APPROVED->value : PaymentReviewStatus::REJECTED->value,
                'reviewer_id' => $reviewer->id,
                'note' => $note,
            ]);

            if ($payment->subscription_id) {
                $this->applyToSubscription($payment, $approve);
            }

            if ($payment->therapy_session_id && $approve) {
                $this->sessionService->markPaidAndConfirmed($payment->session, $reviewer);
            }
        });

        $this->notifications->paymentReviewed($payment->fresh(['subscription.patient.user', 'session.patient.user']));

        return $payment->refresh();
    }

    private function applyToSubscription(Payment $payment, bool $approved): void
    {
        $subscription = $payment->subscription;

        if (! $subscription) {
            return;
        }

        if (! $approved) {
            $this->subscriptions->update($subscription, ['verification_status' => 'rejected']);

            return;
        }

        $weeks = SubscriptionType::from($subscription->type->value)->durationInWeeks();

        $this->subscriptions->update($subscription, [
            'verification_status' => 'approved',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeeks($weeks)->toDateString(),
        ]);

        // Point the patient's profile at the live subscription.
        $subscription->patient?->update(['subscription_id' => $subscription->id]);
    }

    public function pending(int $perPage): LengthAwarePaginator
    {
        return $this->payments->pendingPaginated(max(1, min($perPage, 50)));
    }

    public function toArray(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'subscription_id' => $payment->subscription_id,
            'therapy_session_id' => $payment->therapy_session_id,
            'amount' => $payment->amount,
            'status' => $payment->status?->value,
            'note' => $payment->note,
            'reviewer_id' => $payment->reviewer_id,
            'created_at' => $payment->created_at?->toISOString(),
        ];
    }
}
