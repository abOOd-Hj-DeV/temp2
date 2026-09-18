<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TherapistSwitch;
use App\Services\Therapist\TherapistSwitchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TherapistSwitchController extends Controller
{
    public function __construct(private TherapistSwitchService $switches) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'new_therapist_id' => 'required|uuid',
            'reason' => 'required|string|min:10|max:2000',
        ]);

        $patient = $request->user()->patient
            ?? throw ValidationException::withMessages(['patient' => 'Complete your profile first.']);

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
}
