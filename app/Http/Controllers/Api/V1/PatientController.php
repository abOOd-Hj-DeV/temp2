<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Patient\UpdateProfileRequest;
use App\Services\Patient\PatientAccountService;
use App\Services\Patient\PatientDashboardService;
use App\Services\Patient\PatientProfileService;
use App\Services\Therapist\TherapistContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientController extends Controller
{
    public function __construct(
        private PatientProfileService $profiles,
        private PatientDashboardService $dashboard,
        private PatientAccountService $account,
        private TherapistContentService $therapistContent,
    ) {}

    public function getProfile(Request $request): JsonResponse
    {
        return response()->json([
            'patient' => $this->profiles->getProfile($request->user()),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        return response()->json(
            $this->profiles->upsertProfile($request->user(), $request->validated())
        );
    }

    public function getOnboarding(Request $request): JsonResponse
    {
        return response()->json($this->profiles->getOnboarding($request->user()));
    }

    public function getDashboard(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->dashboard($request->user()));
    }

    public function getProgress(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->progress($request->user()));
    }

    public function getAppointments(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->appointments($request->user()));
    }

    public function getPostSession(Request $request, string $sessionId): JsonResponse
    {
        return response()->json($this->dashboard->postSession($request->user(), $sessionId));
    }

    public function getPrograms(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->programs($request->user()));
    }

    public function getModule(Request $request, string $module): JsonResponse
    {
        return response()->json($this->dashboard->moduleDetail($request->user(), $module));
    }

    public function completeModule(Request $request, string $module): JsonResponse
    {
        return response()->json($this->dashboard->completeModule($request->user(), $module));
    }

    public function getEmergency(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->emergency($request->user()));
    }

    public function getContent(Request $request): JsonResponse
    {
        $items = $this->therapistContent->forPatient(
            $this->patientOf($request), $this->perPage($request)
        );

        return response()->json([
            'data' => $items->through(fn ($i) => $this->therapistContent->toArray($i)),
        ]);
    }

    public function postEmergencyAlert(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->emergencyAlert($request->user()), 201);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        return response()->json($this->account->requestDeletion($request->user()));
    }

    public function exportData(Request $request): StreamedResponse
    {
        $data = $this->account->exportData($request->user());

        return response()->streamDownload(
            fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            'sakina-data-export.json',
            ['Content-Type' => 'application/json']
        );
    }
}
