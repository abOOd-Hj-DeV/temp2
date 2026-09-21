<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionManagementController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    /** Staff cancels a patient's package (no refund; remaining sessions cancelled). */
    public function cancel(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $result = $this->subscriptions->cancel($subscription, $request->user(), $data['reason']);

        return response()->json([
            'message' => 'Package cancelled. Remaining sessions were cancelled; payments are not refunded.',
            'subscription' => $this->subscriptions->toArray($result['subscription']),
            'cancelled_sessions' => $result['cancelled_sessions'],
        ]);
    }
}
