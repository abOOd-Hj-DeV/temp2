<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\TherapistRepositoryInterface;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TherapistApprovalController extends Controller
{
    public function __construct(
        private TherapistRepositoryInterface $therapists,
        private NotificationService $notifications,
    ) {}

    /**
     * Therapists awaiting approval (or filtered by any status).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|in:pending,approved,rejected']);
        $status = $request->input('status', 'pending');

        $paginator = $this->therapists->listByApprovalStatus($status, (int) $request->input('per_page', 15));

        return response()->json([
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function approve(string $id): JsonResponse
    {
        return $this->decide($id, ApprovalStatus::APPROVED);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->decide($id, ApprovalStatus::REJECTED);
    }

    private function decide(string $id, ApprovalStatus $status): JsonResponse
    {
        $therapist = $this->therapists->findByUserId($id);

        if (! $therapist) {
            throw ValidationException::withMessages(['therapist' => 'Therapist not found.']);
        }

        $this->therapists->update($therapist, ['approval_status' => $status->value]);
        $this->notifications->therapistApprovalDecided($therapist->refresh());

        return response()->json([
            'message' => "Therapist {$status->value}.",
            'data' => [
                'user_id' => $therapist->user_id,
                'approval_status' => $therapist->approval_status?->value,
            ],
        ]);
    }
}
