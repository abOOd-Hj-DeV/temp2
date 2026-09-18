<?php

namespace App\Jobs;

use App\Services\Patient\ComplianceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Weekly cron: recompute every enrolled patient's compliance_level.
 * Each patient is isolated so one failure never aborts the batch.
 */
class ComputeWeeklyComplianceJob implements ShouldQueue
{
    use Queueable;

    public function handle(ComplianceService $compliance): void
    {
        $asOf = now();

        foreach ($compliance->enrolledPatients() as $patient) {
            try {
                $compliance->apply($patient, $asOf);
            } catch (\Throwable $e) {
                Log::error('Weekly compliance failed for patient', [
                    'patient_id' => $patient->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
