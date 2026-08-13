<?php
// app/Services/Patient/PatientExportService.php

namespace App\Services\Patient;

use App\Models\User;
use App\Models\Patient;
use App\Repositories\Contracts\PatientRepositoryInterface;
use Illuminate\Support\Facades\Log;

class PatientExportService
{
    public function __construct(
        private PatientRepositoryInterface $patientRepository
    ) {}

    public function exportData(User $user): array
    {
        try {
            $patient = $this->patientRepository->findByUserId($user->id);

            if (!$patient) {
                return [
                    'has_data' => false,
                    'message' => 'No patient data found'
                ];
            }

            $exportData = $this->generateExportData($patient);

            return [
                'has_data' => true,
                'message' => 'Data export request received',
                'export_id' => uniqid('export_'),
                'estimated_time' => '5 دقائق',
                'download_url' => null,
                'status' => 'processing'
            ];

        } catch (\Exception $e) {
            Log::error('Failed to export patient data', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function generateExportData(Patient $patient): array
    {
        return [
            'patient_info' => [
                'full_name' => $patient->full_name,
                'age' => $patient->age,
                'gender' => $patient->gender,
                'language' => $patient->language
            ],
            'generated_at' => now()->toISOString(),
            'data_types' => ['profile', 'assessments', 'sessions']
        ];
    }
}

