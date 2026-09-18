<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\TherapistRepositoryInterface;
use App\Services\Therapist\TherapistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TherapistApprovalController extends Controller
{
    public function __construct(
        private TherapistRepositoryInterface $therapists,
        private TherapistService $therapistService,
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
            'data' => collect($paginator->items())->map(fn ($t) => $this->therapistService->toArray($t) + [
                'clients_count' => $t->clients_count,
                'clients_limit' => $t->clients_limit,
                'license_file_path' => $t->license_file_path,
            ]),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, ApprovalStatus::APPROVED);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, ApprovalStatus::REJECTED);
    }

    /**
     * Admin sets how many distinct clients a therapist may carry.
     */
    public function updateLimit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['clients_limit' => 'required|integer|min:1|max:500']);

        $therapist = $this->therapists->findByUserId($id);

        if (! $therapist) {
            throw ValidationException::withMessages(['therapist' => 'Therapist not found.']);
        }

        $therapist = $this->therapistService->updateClientsLimit($therapist, (int) $data['clients_limit'], $request->user());

        return response()->json([
            'message' => 'Clients limit updated.',
            'data' => ['user_id' => $therapist->user_id, 'clients_limit' => $therapist->clients_limit],
        ]);
    }

    private function decide(Request $request, string $id, ApprovalStatus $status): JsonResponse
    {
        $request->validate(['note' => 'nullable|string|max:1000']);

        $therapist = $this->therapistService->decideApproval($id, $status, $request->user(), $request->input('note'));

        return response()->json([
            'message' => "Therapist {$status->value}.",
            'data' => [
                'user_id' => $therapist->user_id,
                'approval_status' => $therapist->approval_status?->value,
            ],
        ]);
    }
}
