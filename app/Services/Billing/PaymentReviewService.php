<?php

namespace App\Services\Billing;

use App\Enums\PaymentReviewStatus;
use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Exceptions\ConflictException;
use App\Jobs\ReviewPaymentProofJob;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\Session\SessionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class PaymentReviewService
{
    public function __construct(
        private PaymentRepositoryInterface $payments,
        private SubscriptionRepositoryInterface $subscriptions,
        private SessionService $sessionService,
        private NotificationService $notifications,
        private AuditLogService $audit,
    ) {}

    /**
     * Persist a payment row and dispatch the queue job that alerts staff to
     * review the proof manually. The job is only released once the
     * surrounding transaction commits so the worker can always find the row.
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

        ReviewPaymentProofJob::dispatch($payment->id)->afterCommit();

        return $payment;
    }

    /**
     * A patient uploads proof for a payable (non-free, unpaid) session.
     * The session row is locked while the open-payment check and insert
     * run; the partial unique index on pending payments is the backstop.
     */
    public function submitSessionProof(TherapySession $session, UploadedFile $proof): Payment
    {
        $disk = config('sakina.uploads_disk', 'local');
        $path = self::storeProof($proof, "payment-proofs/{$session->patient_id}", $disk);

        try {
            return DB::transaction(function () use ($session, $path) {
                $locked = TherapySession::whereKey($session->id)->lockForUpdate()->firstOrFail();

                if ($locked->payment_status !== PaymentStatus::PENDING) {
                    throw ValidationException::withMessages([
                        'payment_status' => 'This session does not require a payment proof.',
                    ]);
                }

                if ($locked->status === SessionStatus::CANCELLED) {
                    throw ValidationException::withMessages([
                        'payment_status' => 'This session was cancelled.',
                    ]);
                }

                if ($this->payments->hasOpenPaymentForSession($locked->id)) {
                    throw ValidationException::withMessages([
                        'payment' => 'A payment for this session is already under review.',
                    ]);
                }

                return $this->createPayment(
                    amount: (float) $locked->price,
                    proofPath: $path,
                    sessionId: $locked->id,
                );
            });
        } catch (UniqueConstraintViolationException) {
            Storage::disk($disk)->delete($path);
            throw new ConflictException('A payment for this session is already under review.');
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
    }

    /**
     * Persist an uploaded proof, failing closed (503) when the disk rejects it
     * instead of letting an empty path reach the database.
     */
    public static function storeProof(UploadedFile $proof, string $directory, string $disk): string
    {
        try {
            $path = $proof->store($directory, ['disk' => $disk]);
        } catch (\Throwable $e) {
            report($e);
            $path = false;
        }

        if (! is_string($path) || $path === '') {
            throw new ServiceUnavailableHttpException(30, 'The payment proof could not be stored. Please try again.');
        }

        return $path;
    }

    /**
     * Staff decision on a submitted proof.
     *   approve → payment approved; subscription activated OR session paid+confirmed.
     *   reject  → payment rejected; the patient may upload a new proof.
     *
     * The payment row is locked for the whole decision so two reviewers can
     * never both succeed; the loser receives 409.
     */
    public function review(Payment $payment, User $reviewer, string $action, ?string $note): Payment
    {
        $approve = $action === 'approve';

        $payment = DB::transaction(function () use ($payment, $reviewer, $approve, $note) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentReviewStatus::PENDING) {
                throw new ConflictException('This payment was already reviewed.');
            }

            if ($approve) {
                $this->assertProofExists($locked);
            }

            $this->payments->update($locked, [
                'status' => $approve ? PaymentReviewStatus::APPROVED->value : PaymentReviewStatus::REJECTED->value,
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'note' => $note,
            ]);

            if ($locked->subscription_id) {
                $this->applyToSubscription($locked, $approve);
            }

            if ($locked->therapy_session_id) {
                $session = TherapySession::whereKey($locked->therapy_session_id)->lockForUpdate()->firstOrFail();

                if ($approve) {
                    if ($session->status === SessionStatus::CANCELLED) {
                        throw ValidationException::withMessages([
                            'payment' => 'The session was cancelled; reject the payment instead.',
                        ]);
                    }

                    $this->sessionService->markPaidAndConfirmed($session, $reviewer);
                }
            }

            $this->audit->record($reviewer, AuditLogService::PAYMENT_REVIEWED, $locked->id, [
                'action' => $approve ? 'approve' : 'reject',
                'note' => $note,
                'subscription_id' => $locked->subscription_id,
                'therapy_session_id' => $locked->therapy_session_id,
            ]);

            return $locked;
        });

        $this->notifications->deliver('paymentReviewed', $payment->fresh(['subscription.patient.user', 'session.patient.user']));

        return $payment->refresh();
    }

    /**
     * Money must never be recognised against a proof that is empty, was never
     * written, or has since been removed from the private disk.
     */
    private function assertProofExists(Payment $payment): void
    {
        $path = trim((string) $payment->proof_file_path);
        $disk = config('sakina.uploads_disk', 'local');

        if ($path === '' || str_contains($path, '..') || ! Storage::disk($disk)->exists($path)) {
            throw ValidationException::withMessages([
                'payment' => 'The payment proof file is missing; reject the payment and ask the patient to re-upload.',
            ]);
        }
    }

    private function applyToSubscription(Payment $payment, bool $approved): void
    {
        $subscription = Subscription::whereKey($payment->subscription_id)->lockForUpdate()->first();

        if (! $subscription) {
            return;
        }

        if ($subscription->verification_status !== 'pending') {
            throw new ConflictException("The subscription is already {$subscription->verification_status}.");
        }

        if (! $approved) {
            $this->subscriptions->update($subscription, ['verification_status' => 'rejected']);

            return;
        }

        $days = $subscription->duration_days
            ?? $subscription->package?->duration_days
            ?? throw new ConflictException('The subscription has no duration; it cannot be activated.');

        $this->subscriptions->update($subscription, [
            'verification_status' => 'approved',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays($days)->toDateString(),
            'therapist_id' => $subscription->therapist_id ?? $subscription->patient?->therapist_id,
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
        $patient = $payment->subscription?->patient ?? $payment->session?->patient;

        return [
            'id' => $payment->id,
            'subscription_id' => $payment->subscription_id,
            'therapy_session_id' => $payment->therapy_session_id,
            'patient_id' => $patient?->user_id,
            'patient_name' => $patient?->full_name,
            'amount' => $payment->amount,
            'status' => $payment->status?->value,
            'note' => $payment->note,
            'reviewer_id' => $payment->reviewer_id,
            'reviewed_at' => $payment->reviewed_at?->toISOString(),
            'proof_file_path' => $payment->proof_file_path,
            'created_at' => $payment->created_at?->toISOString(),
        ];
    }
}
