<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mood\StoreMoodRequest;
use App\Models\Patient;
use App\Services\Mood\MoodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MoodController extends Controller
{
    public function __construct(private MoodService $moods) {}

    public function store(StoreMoodRequest $request): JsonResponse
    {
        $result = $this->moods->log($this->patient($request), $request->validated());

        return response()->json(['message' => 'Mood logged.'] + $result, $result['created'] ? 201 : 200);
    }

    public function chart(Request $request): JsonResponse
    {
        $request->validate(['days' => 'nullable|integer|min:7|max:90']);

        return response()->json(
            $this->moods->chart($this->patient($request), (int) $request->input('days', 30))
        );
    }

    private function patient(Request $request): Patient
    {
        $patient = $request->user()->patient;

        if (! $patient) {
            throw ValidationException::withMessages(['patient' => 'Complete your profile before logging mood.']);
        }

        return $patient;
    }
}
