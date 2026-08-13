<?php
// app/Http/Controllers/Api/V1/PatientController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Patient\PatientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PatientController extends Controller
{
    public function __construct(private PatientService $patientService) {}

    /**
     * الحصول على بروفايل المريض
     */
    public function getProfile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $patient = $this->patientService->getPatientProfile($user);

            if (!$patient) {
                return response()->json([
                    'message' => 'Patient profile not found. Please complete your profile.',
                    'has_profile' => false,
                ], 200);
            }

            return response()->json([
                'message' => 'Patient profile retrieved successfully.',
                'has_profile' => true,
                'patient' => $patient
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve patient profile', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to load profile',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * تحديث بروفايل المريض
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'full_name' => 'required|string|max:255',
                'age' => 'required|integer|min:18',
                'gender' => 'required|in:male,female,other',
                'language' => 'required|string|max:50',
                'assessment_score' => 'nullable|integer|min:0|max:27',
                'safety_flag' => 'nullable|boolean',
                'compliance_level' => 'nullable|in:high,medium,low',
            ]);

            $user = $request->user();

            Log::info('Patient profile update requested', [
                'user_id' => $user->id,
                'data' => $validated
            ]);

            $result = $this->patientService->updatePatientProfile($user, $validated);

            return response()->json([
                'message' => $result['message'],
                'patient' => $result['patient'],
                'profile_completion' => $result['profile_completion']
            ]);

        } catch (ValidationException $e) {
            Log::warning('Patient profile validation failed', [
                'user_id' => $request->user()?->id,
                'errors' => $e->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to update patient profile', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to update profile. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * الحصول على لوحة تحكم المريض
     */
    public function getDashboard(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            Log::info('Patient dashboard requested', [
                'user_id' => $user->id
            ]);

            $dashboardData = $this->patientService->getDashboardData($user);

            return response()->json([
                'message' => 'Dashboard data retrieved successfully',
                ...$dashboardData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve patient dashboard', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to load dashboard data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * الحصول على بيانات Onboarding
     */
    public function getOnboarding(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            Log::info('Patient onboarding data requested', [
                'user_id' => $user->id
            ]);

            $onboardingData = $this->patientService->getOnboardingData($user);

            return response()->json([
                'message' => 'Onboarding data retrieved successfully',
                ...$onboardingData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve patient onboarding data', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to load onboarding data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * حذف حساب المريض
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            Log::info('Patient account deletion requested', [
                'user_id' => $user->id
            ]);

            $result = $this->patientService->deleteAccount($user);

            return response()->json([
                'message' => $result['message'],
                'deletion_scheduled' => $result['deletion_scheduled'],
                'scheduled_date' => $result['scheduled_date']
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete patient account', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to delete account',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * تصدير بيانات المريض
     */
    public function exportData(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            Log::info('Patient data export requested', [
                'user_id' => $user->id
            ]);

            $exportData = $this->patientService->exportData($user);

            return response()->json([
                'message' => 'Data exported successfully',
                ...$exportData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to export patient data', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to export data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * الحصول على بيانات تقدم المريض
     */
    public function getProgress(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            Log::info('Patient progress requested', [
                'user_id' => $user->id
            ]);

            $progressData = $this->patientService->getProgress($user);

            return response()->json([
                'message' => 'Progress data retrieved successfully',
                ...$progressData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve patient progress', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to load progress data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
     public function getAppointments(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            Log::info('Patient appointments requested', [
                'user_id' => $user->id
            ]);

            $appointments = $this->patientService->getAppointments($user);

            return response()->json([
                'message' => 'Appointments retrieved successfully',
                'appointments' => $appointments
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve patient appointments', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to load appointments',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
      /**
     * الحصول على برامج المريض
     */
    public function getPrograms(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            Log::info('Patient programs requested', [
                'user_id' => $user->id
            ]);

            $programs = $this->patientService->getPrograms($user);

            return response()->json([
                'message' => 'Programs retrieved successfully',
                'programs' => $programs
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve patient programs', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to load programs',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
