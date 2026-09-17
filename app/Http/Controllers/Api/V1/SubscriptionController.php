<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Subscription\StoreSubscriptionRequest;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    /**
     * Patient's subscription history.
     */
    public function index(Request $request): JsonResponse
    {
        $subscriptions = $this->subscriptionService->history($request->user()->patient);

        return response()->json([
            'data' => $subscriptions->map(fn ($s) => $this->subscriptionService->toArray($s)),
        ]);
    }

    /**
     * The currently active subscription, if any.
     */
    public function current(Request $request): JsonResponse
    {
        $subscription = $this->subscriptionService->current($request->user()->patient);

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
            $request->user()->patient,
            $request->input('type'),
            $request->file('proof')
        );

        return response()->json([
            'message' => 'Subscription created. Your payment proof is under review.',
            'subscription' => $this->subscriptionService->toArray($result['subscription']),
            'payment' => [
                'id' => $result['payment']->id,
                'amount' => $result['payment']->amount,
                'status' => $result['payment']->status?->value,
            ],
        ], 201);
    }
}
