<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Subscription\StoreSubscriptionRequest;
use App\Models\Package;
use App\Models\Subscription;
use App\Services\Package\PackageService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private PackageService $packages,
    ) {}

    /** Published packages a patient may buy, with the commercial terms. */
    public function packages(): JsonResponse
    {
        return response()->json([
            'data' => $this->packages->published()->map(fn (Package $p) => $this->packages->toArray($p)),
            'policy' => SubscriptionService::policy(),
        ]);
    }

    /**
     * Patient cancels their own package: remaining sessions are cancelled,
     * nothing is refunded.
     */
    public function cancel(Request $request, string $subscription): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $model = Subscription::where('patient_id', $this->patientOf($request)->user_id)
            ->whereKey($subscription)
            ->firstOrFail();

        $result = $this->subscriptionService->cancel($model, $request->user(), $data['reason'] ?? null);

        return response()->json([
            'message' => 'Package cancelled. Remaining sessions were cancelled; payments are not refunded.',
            'subscription' => $this->subscriptionService->toArray($result['subscription']),
            'cancelled_sessions' => $result['cancelled_sessions'],
        ]);
    }

    /**
     * Patient's subscription history.
     */
    public function index(Request $request): JsonResponse
    {
        $subscriptions = $this->subscriptionService->history($this->patientOf($request));

        return response()->json([
            'data' => $subscriptions->map(fn ($s) => $this->subscriptionService->toArray($s)),
        ]);
    }

    /**
     * The currently active subscription, if any.
     */
    public function current(Request $request): JsonResponse
    {
        $subscription = $this->subscriptionService->current($this->patientOf($request));

        return response()->json([
            'data' => $subscription ? $this->subscriptionService->toArray($subscription) : null,
        ]);
    }

    /**
     * Subscribe: upload the payment proof (stored on the configured disk —
     * S3 in production) and queue the manual-review job.
     */
    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $result = $this->subscriptionService->createWithProof(
            $this->patientOf($request),
            (string) ($request->input('package_id') ?: $request->input('type')),
            $request->file('proof')
        );

        return response()->json([
            'message' => 'Subscription created. Your payment proof is under review.',
            'policy' => SubscriptionService::policy(),
            'subscription' => $this->subscriptionService->toArray($result['subscription']),
            'payment' => [
                'id' => $result['payment']->id,
                'amount' => $result['payment']->amount,
                'status' => $result['payment']->status?->value,
            ],
        ], 201);
    }
}
