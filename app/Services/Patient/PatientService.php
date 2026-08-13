<?php
// app/Services/Patient/PatientService.php

namespace App\Services\Patient;

class PatientService
{
    public function __construct(
        private PatientProfileService $profileService,
        private PatientDashboardService $dashboardService,
        private PatientOnboardingService $onboardingService,
        private PatientAppointmentService $appointmentService,
        private PatientProgramService $programService,
        private PatientAccountService $accountService,
        private PatientExportService $exportService,
        private PatientProgressService $progressService
    ) {}

    public function getPatientProfile($user): ?\App\Models\Patient
    {
        return $this->profileService->getPatientProfile($user);
    }

    public function updatePatientProfile($user, array $data): array
    {
        return $this->profileService->updatePatientProfile($user, $data);
    }

    public function getDashboardData($user): array
    {
        return $this->dashboardService->getDashboardData($user);
    }

    public function getOnboardingData($user): array
    {
        return $this->onboardingService->getOnboardingData($user);
    }

    public function getAppointments($user): array
    {
        return $this->appointmentService->getAppointments($user);
    }

    public function getPrograms($user): array
    {
        return $this->programService->getPrograms($user);
    }

    public function deleteAccount($user): array
    {
        return $this->accountService->deleteAccount($user);
    }

    public function exportData($user): array
    {
        return $this->exportService->exportData($user);
    }

    public function getProgress($user): array
    {
        return $this->progressService->getProgress($user);
    }
}
