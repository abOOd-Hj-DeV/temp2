<?php

namespace App\Services\Subscription;

use App\Exceptions\ConflictException;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Subscription;
use App\Repositories\Contracts\PaymentRepositoryInterface;
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
            'created_at' => $subscription->created_at?->toISOString(),
        ];
    }
}
