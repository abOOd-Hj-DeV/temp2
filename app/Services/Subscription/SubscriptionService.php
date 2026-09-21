<?php

namespace App\Services\Subscription;

use App\Exceptions\ConflictException;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\SessionRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Services\AuditLogService;
use App\Services\Billing\PaymentReviewService;
use App\Services\Package\PackageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptions,
        private PaymentRepositoryInterface $payments,
        private PaymentReviewService $paymentReview,
        private AuditLogService $audit,
        private PackageService $packages,
        private SessionRepositoryInterface $sessions,
    ) {}

    /**
     * Create a pending subscription + payment from an uploaded proof file.
     * The file lands on the configured disk (S3 in production) and a queue
     * job is dispatched so staff can review it manually.
     */
    public function createWithProof(Patient $patient, string $packageIdOrCode, UploadedFile $proof): array
    {
        $package = $this->packages->findPublished($packageIdOrCode)
            ?? throw ValidationException::withMessages(['package_id' => 'The selected package is not available.']);
        $price = (float) $package->price;
        $disk = config('sakina.uploads_disk', 'local');
        $path = PaymentReviewService::storeProof($proof, "payment-proofs/{$patient->user_id}", $disk);

        try {
            $result = DB::transaction(function () use ($patient, $package, $path, $price) {
                // Serialise concurrent submissions from the same patient.
                Patient::whereKey($patient->user_id)->lockForUpdate()->firstOrFail();

                if ($this->subscriptions->hasPendingOrActive($patient->user_id)) {
                    throw ValidationException::withMessages([
                        'subscription' => 'You already have a pending or active subscription.',
                    ]);
                }

                $subscription = $this->subscriptions->create([
                    'patient_id' => $patient->user_id,
                    'therapist_id' => $patient->therapist_id,
                    'type' => $package->code,
                    'package_id' => $package->id,
                    'sessions_total' => $package->number_of_sessions,
                    'duration_days' => $package->duration_days,
                    'daily_sessions_quota' => $package->daily_sessions_quota,
                    'start_date' => null, // dates are set on approval
                    'end_date' => null,
                    'price' => $price,
                    'payment_proof_path' => $path,
                    'verification_status' => 'pending',
                ]);

                $payment = $this->paymentReview->createPayment(
                    amount: $price,
                    proofPath: $path,
                    subscriptionId: $subscription->id,
                );

                $this->audit->record($patient->user_id, AuditLogService::SUBSCRIPTION_CREATED, $subscription->id, [
                    'package_id' => $package->id,
                    'price' => $price,
                    'payment_id' => $payment->id,
                ]);

                return ['subscription' => $subscription, 'payment' => $payment];
            });
        } catch (UniqueConstraintViolationException) {
            Storage::disk($disk)->delete($path);
            throw new ConflictException('You already have a pending or active subscription.');
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }

        return $result;
    }

    /**
     * Cancel a package. Cancellation is the only exit from a purchased
     * package: every pending/confirmed session charged to it is cancelled and
     * no money is refunded (the free initial session and introductory module
     * are the trial). Completed sessions and their revenue are untouched.
     *
     * @return array{subscription: Subscription, cancelled_sessions: int}
     */
    public function cancel(Subscription $subscription, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($subscription, $actor, $reason) {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($locked->cancelled_at !== null) {
                throw new ConflictException('This package is already cancelled.');
            }

            if ($locked->verification_status === 'rejected') {
                throw new ConflictException('A rejected package cannot be cancelled.');
            }

            if ($locked->verification_status === 'approved' && ! $locked->is_active) {
                throw new ConflictException('This package has already ended.');
            }

            $sessions = $this->sessions->cancelOpenForSubscription($locked->id);

            foreach ($sessions as $session) {
                $session->statusLogs()->create([
                    'from_status' => $session->status->value,
                    'to_status' => 'cancelled',
                    'actor_id' => $actor->id,
                ]);
            }

            $this->subscriptions->update($locked, [
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ]);

            $this->audit->record($actor, AuditLogService::SUBSCRIPTION_CANCELLED, $locked->id, [
                'patient_id' => $locked->patient_id,
                'verification_status' => $locked->verification_status,
                'cancelled_sessions' => $sessions->count(),
                'refunded' => false,
            ]);

            return ['subscription' => $locked->fresh(), 'cancelled_sessions' => $sessions->count()];
        });
    }

    /** Commercial terms every client must be able to show before purchase. */
    public static function policy(): array
    {
        return [
            'refundable' => (bool) config('sakina.package_policy.refundable', false),
            'cancellation_unit' => (string) config('sakina.package_policy.cancellation_unit', 'package'),
            'trial' => 'The first session and the introductory module are free before purchase.',
            'cancellation' => 'Cancelling a package stops all remaining sessions; payments are not refunded.',
        ];
    }

    public function current(Patient $patient): ?Subscription
    {
        return $this->subscriptions->activeForPatient($patient->user_id);
    }

    public function history(Patient $patient): Collection
    {
        return $this->subscriptions->forPatient($patient->user_id);
    }

    public function toArray(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'type' => $subscription->type,
            'package_id' => $subscription->package_id,
            'sessions_total' => $subscription->sessions_total,
            'duration_days' => $subscription->duration_days,
            'daily_sessions_quota' => $subscription->daily_sessions_quota,
            'start_date' => $subscription->start_date?->toDateString(),
            'end_date' => $subscription->end_date?->toDateString(),
            'price' => $subscription->price,
            'verification_status' => $subscription->verification_status,
            'is_active' => $subscription->is_active,
            'sessions_used' => $this->sessions->countNonCancelledForSubscription($subscription->id),
            'cancelled_at' => $subscription->cancelled_at?->toISOString(),
            'cancellation_reason' => $subscription->cancellation_reason,
            'policy' => self::policy(),
            'created_at' => $subscription->created_at?->toISOString(),
        ];
    }
}
