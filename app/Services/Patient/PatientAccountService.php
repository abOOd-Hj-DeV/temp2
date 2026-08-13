<?php
// app/Services/Patient/PatientAccountService.php

namespace App\Services\Patient;

use App\Models\User;
use App\Repositories\Contracts\PatientRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PatientAccountService
{
    public function __construct(
        private PatientRepositoryInterface $patientRepository
    ) {}

    public function deleteAccount(User $user): array
    {
        return DB::transaction(function () use ($user) {
            try {
                $patient = $this->patientRepository->findByUserId($user->id);
                $deletionDate = now()->addDays(30);
                $user->update(['is_active' => false]);

                Log::info('Patient account deletion scheduled', [
                    'user_id' => $user->id,
                    'deletion_date' => $deletionDate
                ]);

                return [
                    'message' => 'Account deletion scheduled successfully. Your data will be deleted in 30 days.',
                    'deletion_scheduled' => true,
                    'scheduled_date' => $deletionDate->toISOString()
                ];

            } catch (\Exception $e) {
                Log::error('Failed to schedule account deletion', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }
}
