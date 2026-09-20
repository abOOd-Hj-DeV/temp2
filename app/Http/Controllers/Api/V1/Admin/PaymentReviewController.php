<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payment\ReviewPaymentRequest;
use App\Models\Payment;
use App\Services\Billing\PaymentReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReviewController extends Controller
{
    public function __construct(
        private PaymentReviewService $paymentReview,
    ) {}

    /**
     * Queue of proofs awaiting manual review.
     */
    public function pending(Request $request): JsonResponse
    {
        $paginator = $this->paymentReview->pending($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($p) => $this->paymentReview->toArray($p)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Approve or reject a proof.
     *   approve → subscription activates (dates start now) or the session
     *             becomes paid + confirmed.
     *   reject  → patient is notified and may upload a new proof.
     */
    public function review(ReviewPaymentRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->paymentReview->review(
            $payment,
            $request->user(),
            $request->input('action'),
            $request->input('note')
        );

        return response()->json([
            'message' => 'Payment reviewed.',
            'payment' => $this->paymentReview->toArray($payment),
        ]);
    }
}
