<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TherapistBlockedPeriod;
use App\Services\Therapist\TherapistBlockedPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Therapist vacations / closed days that override the weekly availability. */
class TherapistBlockedPeriodController extends Controller
{
    public function __construct(private TherapistBlockedPeriodService $periods) {}

    public function index(Request $request): JsonResponse
    {
        $periods = $this->periods->list($this->therapistOf($request), $request->boolean('include_past'));

        return response()->json([
            'data' => $periods->map(fn (TherapistBlockedPeriod $p) => $this->periods->toArray($p))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $period = $this->periods->create($this->therapistOf($request), $data);

        return response()->json([
            'message' => 'Blocked period added.',
            'data' => $this->periods->toArray($period),
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->periods->delete($this->therapistOf($request), $id);

        return response()->json(['message' => 'Blocked period removed.']);
    }
}
