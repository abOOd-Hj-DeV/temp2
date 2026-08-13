<?php
// app/Services/Patient/PatientProfileService.php

namespace App\Services\Patient;

use App\Models\User;
use App\Models\Patient;
use App\Repositories\Contracts\PatientRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PatientProfileService
{
    public function __construct(
        private PatientRepositoryInterface $patientRepository,
        private PatientHelperService $helperService
    ) {}

    public function getPatientProfile(User $user): ?Patient
    {
        return $this->patientRepository->findByUserId($user->id);
    }

    public function updatePatientProfile(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            try {
                $patient = $this->patientRepository->findByUserId($user->id);

                if ($patient) {
                    $this->patientRepository->update($patient, $data);
                    $action = 'updated';
                    $message = 'Profile updated successfully';
                } else {
                    $patientData = array_merge(['user_id' => $user->id], $data);
                    $patient = $this->patientRepository->create($patientData);
                    $action = 'created';
                    $message = 'Profile created successfully';
                }

                $patientWithDetails = $this->patientRepository->findByUserId($user->id);
                $completion = $this->helperService->calculateProfileCompletion($patientWithDetails);
                $safetyCheck = $this->helperService->checkSafetyConcerns($data);

                Log::info('Patient profile ' . $action, [
                    'user_id' => $user->id,
                    'patient_id' => $patient->user_id,
                    'completion_percentage' => $completion,
                    'safety_flag' => $safetyCheck['has_concern']
                ]);

                return [
                    'message' => $message,
                    'patient' => $this->helperService->formatPatientForResponse($patientWithDetails),
                    'profile_completion' => [
                        'percentage' => $completion,
                        'level' => $this->helperService->getCompletionLevel($completion),
                        'next_steps' => $this->helperService->getNextProfileSteps($patientWithDetails)
                    ],
                    'safety_check' => $safetyCheck
                ];

            } catch (\Exception $e) {
                Log::error('Failed to update patient profile in transaction', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                throw $e;
            }
        });
    }
}

