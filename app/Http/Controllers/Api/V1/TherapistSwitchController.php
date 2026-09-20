<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TherapistSwitch;
use App\Services\Therapist\TherapistSwitchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TherapistSwitchController extends Controller
{
    public function __construct(private TherapistSwitchService $switches) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_therapist_id' => 'required|uuid',
            'reason' => 'required|string|min:10|max:2000',
        ]);

        $patient = $this->patientOf($request);

        $switch = $this->switches->request($patient, $data['new_therapist_id'], $data['reason'], $request->user());

        return response()->json([
            'message' => 'Therapist switch requested. Our team will review it shortly.',
            'data' => $this->switches->toArray($switch),
        ], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = TherapistSwitch::where('patient_id', $request->user()->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($s) => $this->switches->toArray($s))->all()]);
    }

    /** Requests addressed to the authenticated (approved) therapist. */
    public function incoming(Request $request): JsonResponse
    {
        $rows = TherapistSwitch::where('new_therapist_id', $request->user()->id)
            ->where('status', 'requested')
            ->whereNull('therapist_decision')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $rows->map(fn ($s) => $this->switches->toArray($s))->all()]);
    }

    /** Target therapist accepts or declines; ownership is enforced in the service. */
    public function therapistDecide(Request $request, TherapistSwitch $switch): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:accept,decline',
            'note' => 'nullable|string|max:1000',
        ]);

        $switch = $this->switches->therapistDecide($switch, $data['action'] === 'accept', $request->user(), $data['note'] ?? null);

        return response()->json([
            'message' => $switch->status === 'rejected'
                ? 'Switch request declined.'
                : 'Accepted. The request now awaits the clinical supervisor\'s final decision.',
            'data' => $this->switches->toArray($switch),
        ]);
    }
}
