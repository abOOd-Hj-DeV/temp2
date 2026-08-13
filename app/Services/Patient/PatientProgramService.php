<?php
// app/Services/Patient/PatientProgramService.php

namespace App\Services\Patient;

use App\Models\User;
use App\Models\Program;
use App\Models\PatientModule;
use App\Repositories\Contracts\PatientRepositoryInterface;
use Illuminate\Support\Facades\Log;

class PatientProgramService
{
    public function __construct(
        private PatientRepositoryInterface $patientRepository
    ) {}

    public function getPrograms(User $user): array
    {
        try {
            $patient = $this->patientRepository->findByUserId($user->id);

            if (!$patient) {
                return [
                    'has_profile' => false,
                    'message' => 'Please complete your profile to view programs',
                    'programs' => []
                ];
            }

            $corePrograms = Program::where('is_core', true)
                ->get()
                ->map(function ($program) {
                    return [
                        'id' => $program->id,
                        'name' => $program->name,
                        'description' => $program->description,
                        'is_core' => $program->is_core,
                        'type' => 'core'
                    ];
                });

            $customPrograms = collect([]);
            if ($patient->subscription_id) {
                $customPrograms = Program::where('is_core', false)
                    ->limit(5)
                    ->get()
                    ->map(function ($program) {
                        return [
                            'id' => $program->id,
                            'name' => $program->name,
                            'description' => $program->description,
                            'is_core' => $program->is_core,
                            'type' => 'custom'
                        ];
                    });
            }

            $patientModules = PatientModule::where('patient_id', $patient->user_id)
                ->with('module')
                ->get();

            $modulesProgress = [];
            foreach ($patientModules as $patientModule) {
                if ($patientModule->module && $patientModule->module->program_id) {
                    $programId = $patientModule->module->program_id;
                    if (!isset($modulesProgress[$programId])) {
                        $modulesProgress[$programId] = [
                            'completed' => 0,
                            'total' => 0
                        ];
                    }
                    $modulesProgress[$programId]['total']++;
                    if ($patientModule->status === 'completed') {
                        $modulesProgress[$programId]['completed']++;
                    }
                }
            }

            $allPrograms = $corePrograms->merge($customPrograms)->map(function ($program) use ($modulesProgress) {
                $progress = $modulesProgress[$program['id']] ?? ['completed' => 0, 'total' => 0];
                $percentage = $progress['total'] > 0 ? ($progress['completed'] / $progress['total']) * 100 : 0;

                return array_merge($program, [
                    'progress' => [
                        'completed' => $progress['completed'],
                        'total' => $progress['total'],
                        'percentage' => round($percentage, 2)
                    ],
                    'status' => match(true) {
                        $percentage >= 100 => 'completed',
                        $percentage > 0 => 'in_progress',
                        default => 'not_started'
                    }
                ]);
            });

            return [
                'has_profile' => true,
                'total_programs' => $allPrograms->count(),
                'programs' => $allPrograms,
                'progress_summary' => [
                    'completed_programs' => $allPrograms->where('status', 'completed')->count(),
                    'in_progress_programs' => $allPrograms->where('status', 'in_progress')->count(),
                    'not_started_programs' => $allPrograms->where('status', 'not_started')->count(),
                    'overall_completion' => $allPrograms->avg('progress.percentage') ?? 0
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get patient programs', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

