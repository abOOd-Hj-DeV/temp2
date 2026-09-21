<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Booking\BookSessionRequest;
use App\Http\Requests\Api\V1\Payment\SubmitProofRequest;
use App\Http\Requests\Api\V1\Session\CompleteSessionRequest;
use App\Http\Requests\Api\V1\Session\SessionLinkRequest;
use App\Models\TherapySession;
use App\Services\Billing\PaymentReviewService;
use App\Services\Session\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private SessionService $sessionService,
        private PaymentReviewService $paymentReview,
    ) {}

    /**
     * Patient books a session — price is 0 for the first-ever session when
     * an approved subscription is active; otherwise the configured price.
     */
    public function book(BookSessionRequest $request): JsonResponse
    {
        $session = $this->sessionService->book($this->patientOf($request), $request->validated());

        return response()->json([
            'message' => 'Session booked.',
            'session' => $this->sessionService->toArray($session, $request->user()),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->sessionService->forPatient(
            $request->user()->id,
            $this->perPage($request)
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($s) => $this->sessionService->toArray($s, $request->user())),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, TherapySession $session): JsonResponse
    {
        $this->assertParticipant($request, $session);

        return response()->json([
            'session' => $this->sessionService->toArray($session, $request->user()),
            'status_log' => $this->sessionService->statusLog($session),
        ]);
    }

    public function cancel(Request $request, TherapySession $session): JsonResponse
    {
        $this->assertParticipant($request, $session);

        $session = $this->sessionService->cancel($session, $request->user());

        return response()->json([
            'message' => 'Session cancelled.',
            'session' => $this->sessionService->toArray($session, $request->user()),
        ]);
    }

    /**
     * Patient uploads the payment proof for a non-free session.
     */
    public function submitProof(SubmitProofRequest $request, TherapySession $session): JsonResponse
    {
        if ($session->patient_id !== $request->user()->id) {
            abort(403, 'Not your session.');
        }

        $payment = $this->paymentReview->submitSessionProof($session, $request->file('proof'));

        return response()->json([
            'message' => 'Payment proof submitted for review.',
            'payment' => $this->paymentReview->toArray($payment),
        ], 202);
    }

    /** Patient confirms the session took place (after its start time). */
    public function confirmAttendance(Request $request, TherapySession $session): JsonResponse
    {
        $session = $this->sessionService->confirmAttendance($session, $request->user());

        return response()->json(['message' => 'Attendance confirmed.', 'session' => $this->sessionService->toArray($session, $request->user())]);
    }

    /** Patient asks to move the session; the therapist must approve. */
    public function requestReschedule(Request $request, TherapySession $session): JsonResponse
    {
        $this->assertParticipant($request, $session);

        $data = $request->validate([
            'session_date' => 'required|date_format:Y-m-d',
            'session_time' => 'required|date_format:H:i',
        ]);

        $session = $this->sessionService->requestReschedule($session, $request->user(), $data['session_date'], $data['session_time']);

        return response()->json([
            'message' => 'Reschedule requested; awaiting your therapist\'s approval.',
            'session' => $this->sessionService->toArray($session, $request->user()),
        ], 202);
    }

    /** Therapist approves or rejects the pending reschedule request. */
    public function decideReschedule(Request $request, TherapySession $session): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:approve,reject']);

        $session = $this->sessionService->decideReschedule($session, $request->user(), $data['action'] === 'approve');

        return response()->json([
            'message' => $data['action'] === 'approve' ? 'Session rescheduled.' : 'Reschedule request rejected.',
            'session' => $this->sessionService->toArray($session, $request->user()),
        ]);
    }

    /** Therapist confirms a paid/free pending session. */
    public function confirm(Request $request, TherapySession $session): JsonResponse
    {
        $session = $this->sessionService->confirm($session, $request->user());

        return response()->json(['message' => 'Session confirmed.', 'session' => $this->sessionService->toArray($session, $request->user())]);
    }

    /** Therapist completes a confirmed session with an optional summary. */
    public function complete(CompleteSessionRequest $request, TherapySession $session): JsonResponse
    {
        $session = $this->sessionService->complete($session, $request->user(), $request->input('summary'));

        return response()->json(['message' => 'Session completed.', 'session' => $this->sessionService->toArray($session, $request->user())]);
    }

    /** Therapist writes the post-session report (completes a confirmed session). */
    public function report(Request $request, TherapySession $session): JsonResponse
    {
        $data = $request->validate(['summary' => 'required|string|min:10|max:5000']);

        $session = $this->sessionService->report($session, $request->user(), $data['summary']);

        return response()->json(['message' => 'Report saved.', 'session' => $this->sessionService->toArray($session, $request->user())]);
    }

    /** Therapist attaches the meeting link. */
    public function setLink(SessionLinkRequest $request, TherapySession $session): JsonResponse
    {
        $session = $this->sessionService->setLink($session, $request->user(), $request->input('link'));

        return response()->json(['message' => 'Link saved.', 'session' => $this->sessionService->toArray($session, $request->user())]);
    }

    private function assertParticipant(Request $request, TherapySession $session): void
    {
        $userId = $request->user()->id;

        if ($userId !== $session->patient_id && $userId !== $session->therapist_id) {
            abort(403, 'You are not a participant of this session.');
        }
    }
}
