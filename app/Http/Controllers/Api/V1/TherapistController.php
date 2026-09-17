<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Therapist\SubmitApprovalRequest;
use App\Http\Requests\Api\V1\Therapist\UpdateTherapistSettingsRequest;
use App\Repositories\Contracts\TherapistRepositoryInterface;
use App\Services\Session\SessionService;
use App\Services\Therapist\TherapistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TherapistController extends Controller
{
    public function __construct(
        private TherapistService $therapistService,
        private SessionService $sessionService,
        private TherapistRepositoryInterface $therapists,
    ) {}

    /**
     * Patient-facing list of approved therapists.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'specialty' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:10',
            'accepting_clients' => 'nullable|boolean',
        ]);

        $filters['accepting_clients'] = filter_var($filters['accepting_clients'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $paginator = $this->therapistService->listTherapists($filters, (int) $request->input('per_page', 15));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($t) => $this->therapistService->toArray($t)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Patient-facing single therapist profile.
     */
    public function show(string $id): JsonResponse
    {
        $therapist = $this->therapistService->getTherapist($id);

        return response()->json(['data' => $this->therapistService->toArray($therapist)]);
    }

    /**
     * Bookable slots for a therapist on a given date.
     */
    public function slots(Request $request, string $id): JsonResponse
    {
        $request->validate(['date' => 'required|date|after_or_equal:today']);

        $therapist = $this->therapistService->getTherapist($id);

        return response()->json([
            'date' => $request->input('date'),
            'slots' => $this->therapistService->availableSlots($therapist, Carbon::parse($request->input('date'))),
        ]);
    }

    /**
     * Therapist self dashboard.
     */
    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->therapistService->dashboard($request->user()->therapist));
    }

    /**
     * Therapist self settings (availability, languages, bio...).
     */
    public function updateSettings(UpdateTherapistSettingsRequest $request): JsonResponse
    {
        $therapist = $this->therapistService->updateSettings(
            $request->user()->therapist,
            $request->validated()
        );

        return response()->json([
            'message' => 'Settings updated.',
            'data' => $this->therapistService->toArray($therapist),
        ]);
    }

    /**
     * Therapist submits (or re-submits) their profile for admin approval.
     */
    public function submitApproval(SubmitApprovalRequest $request): JsonResponse
    {
        $therapist = $this->therapistService->submitForApproval(
            $request->user()->therapist,
            $request->file('license')
        );

        return response()->json([
            'message' => 'Profile submitted for approval.',
            'data' => $this->therapistService->toArray($therapist),
        ], 202);
    }

    /**
     * Sessions assigned to the authenticated therapist.
     */
    public function sessions(Request $request): JsonResponse
    {
        $paginator = $this->sessionService->forTherapist(
            $request->user()->id,
            (int) $request->input('per_page', 15)
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($s) => $this->sessionService->toArray($s)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
