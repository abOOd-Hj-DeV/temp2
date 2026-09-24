<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Therapist\TherapistModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TherapistModuleController extends Controller
{
    public function __construct(private TherapistModuleService $modules) {}

    public function progress(Request $request, string $patientId): JsonResponse
    {
        return response()->json($this->modules->progress($this->therapistOf($request), $patientId));
    }

    public function hide(Request $request, string $patientId, string $moduleId): JsonResponse
    {
        return response()->json([
            'message' => 'Module hidden for this client.',
            'module' => $this->modules->hide($this->therapistOf($request), $patientId, $moduleId),
        ]);
    }

    public function unhide(Request $request, string $patientId, string $moduleId): JsonResponse
    {
        return response()->json([
            'message' => 'Module visible again for this client.',
            'module' => $this->modules->unhide($this->therapistOf($request), $patientId, $moduleId),
        ]);
    }
}
