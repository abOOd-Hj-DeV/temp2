<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Therapist\SubmitApprovalRequest;
use App\Http\Requests\Api\V1\Therapist\UpdateTherapistSettingsRequest;
use App\Repositories\Contracts\TherapistRepositoryInterface;
use App\Services\Session\SessionService;
use App\Services\Therapist\TherapistClientService;
use App\Services\Therapist\TherapistReportService;
use App\Services\Therapist\TherapistService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TherapistController extends Controller
{
    public function __construct(
        private TherapistService $therapistService,
        private SessionService $sessionService,
        private TherapistRepositoryInterface $therapists,
        private TherapistClientService $clients,
        private WalletService $wallet,
        private TherapistReportService $reports,
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

        $paginator = $this->therapistService->listTherapists($filters, $this->perPage($request));

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
     * Bookable slots for a therapist on a given date, in the caller's timezone.
     */
    public function slots(Request $request, string $id): JsonResponse
    {
        $request->validate(['date' => 'required|date_format:Y-m-d']);

        $timezone = $request->user()->timezone();
        $date = $request->input('date');

        if ($date < now($timezone)->toDateString()) {
            throw ValidationException::withMessages(['date' => 'The date must be today or later.']);
        }

        $therapist = $this->therapistService->getTherapist($id);
        $slots = $this->therapistService->availableSlotsFor($therapist, $date, $timezone);

        return response()->json([
            'date' => $date,
            'timezone' => $timezone,
            'slots' => array_column($slots, 'time'),
            'slot_details' => $slots,
        ]);
    }

    /**
     * Therapist self dashboard.
     */
    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->therapistService->dashboard($this->therapistOf($request)));
    }

    /**
     * Therapist self settings (availability, languages, bio...).
     */
    public function updateSettings(UpdateTherapistSettingsRequest $request): JsonResponse
    {
        $therapist = $this->therapistService->updateSettings(
            $this->therapistOf($request),
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
            $this->therapistOf($request),
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

    // ---- Clients -----------------------------------------------------------

    public function clients(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(TherapistClientService::STATUSES)],
            'sort' => ['nullable', Rule::in(TherapistClientService::SORTS)],
        ]);
        $therapist = $this->therapistOf($request);

        $paginator = $this->clients->list(
            $therapist,
            $this->perPage($request),
            $filters['search'] ?? null,
            $filters['status'] ?? null,
            $filters['sort'] ?? 'name',
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($p) => $this->clients->clientToArray($p, $therapist)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function client(Request $request, string $id): JsonResponse
    {
        return response()->json($this->clients->show($this->therapistOf($request), $id));
    }

    public function clientNotes(Request $request, string $id): JsonResponse
    {
        return response()->json($this->clients->notes($this->therapistOf($request), $id));
    }

    public function addClientNote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'body' => 'required|string|min:3|max:5000',
            'session_id' => 'nullable|uuid',
        ]);

        $note = $this->clients->addNote($this->therapistOf($request), $id, $data['body'], $data['session_id'] ?? null);

        return response()->json(['message' => 'Note added.', 'note' => $this->clients->noteToArray($note)], 201);
    }

    // ---- Wallet & reports --------------------------------------------------

    public function wallet(Request $request): JsonResponse
    {
        return response()->json($this->wallet->summary($this->therapistOf($request)));
    }

    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:100000',
            'payout_details' => 'required|array',
            'payout_details.method' => 'required|string|in:bank_transfer,paypal,wise,other',
            'payout_details.account' => 'required|string|max:255',
            'payout_details.holder_name' => 'nullable|string|max:255',
        ]);

        $withdrawal = $this->wallet->requestWithdrawal(
            $this->therapistOf($request),
            (float) $data['amount'],
            $data['payout_details'],
            $request->user(),
        );

        return response()->json([
            'message' => 'Withdrawal request submitted for review.',
            'withdrawal' => $this->wallet->withdrawalToArray($withdrawal),
        ], 202);
    }

    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        return response()->json($this->reports->build($this->therapistOf($request), $data['from'] ?? null, $data['to'] ?? null));
    }
}
