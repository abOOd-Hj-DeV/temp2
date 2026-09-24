<?php

namespace App\Services\Therapist;

use App\Models\Module;
use App\Models\PatientModule;
use App\Models\Program;
use App\Models\Therapist;
use App\Services\AuditLogService;
use App\Services\Program\ModuleAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Therapist view of a client's programme progress (incl. submitted homework)
 * and per-client hiding of modules the library marks as hideable.
 */
class TherapistModuleService
{
    public function __construct(
        private TherapistClientService $clients,
        private ModuleAccessService $access,
        private AuditLogService $audit,
    ) {}

    public function progress(Therapist $therapist, string $patientId): array
    {
        $patient = $this->clients->requireClient($therapist, $patientId);

        return [
            'patient_id' => $patient->user_id,
            'has_active_subscription' => $this->access->hasActiveSubscription($patient),
            'programs' => Program::orderByDesc('is_core')->get()->map(fn (Program $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'modules' => $this->access->programState($patient, $p->id)
                    ->map(fn (array $row) => $this->rowToArray($row))->values()->all(),
            ])->all(),
        ];
    }

    public function hide(Therapist $therapist, string $patientId, string $moduleId): array
    {
        $patient = $this->clients->requireClient($therapist, $patientId);
        $module = Module::find($moduleId) ?? throw new NotFoundHttpException('Module not found.');

        if (! $module->is_hideable) {
            throw ValidationException::withMessages(['module' => __('This module cannot be hidden.')]);
        }

        DB::transaction(function () use ($patient, $module, $therapist) {
            $row = PatientModule::firstOrCreate(
                ['patient_id' => $patient->user_id, 'module_id' => $module->id],
                ['id' => (string) Str::uuid(), 'status' => 'pending'],
            );

            if ($row->status === 'completed') {
                throw ValidationException::withMessages(['module' => __('A completed module cannot be hidden.')]);
            }

            $row->update(['hidden_by' => $therapist->user_id, 'hidden_at' => now()]);
        });

        $this->audit->record($therapist->user, AuditLogService::MODULE_HIDDEN, $module->id, ['patient_id' => $patient->user_id]);

        return $this->rowToArray($this->access->stateFor($patient, $module));
    }

    public function unhide(Therapist $therapist, string $patientId, string $moduleId): array
    {
        $patient = $this->clients->requireClient($therapist, $patientId);
        $module = Module::find($moduleId) ?? throw new NotFoundHttpException('Module not found.');

        PatientModule::where('patient_id', $patient->user_id)
            ->where('module_id', $module->id)
            ->whereNotNull('hidden_at')
            ->update(['hidden_by' => null, 'hidden_at' => null]);

        $this->audit->record($therapist->user, AuditLogService::MODULE_UNHIDDEN, $module->id, ['patient_id' => $patient->user_id]);

        return $this->rowToArray($this->access->stateFor($patient, $module));
    }

    private function rowToArray(array $row): array
    {
        /** @var Module $module */
        $module = $row['module'];
        /** @var ?PatientModule $progress */
        $progress = $row['progress'];

        return [
            'id' => $module->id,
            'title' => $module->title,
            'order' => (int) $module->order,
            'is_hideable' => (bool) $module->is_hideable,
            'is_hidden' => $row['lock_reason'] === ModuleAccessService::REASON_HIDDEN,
            'locked' => $row['locked'],
            'lock_reason' => $row['lock_reason'],
            'status' => $progress?->status ?? 'pending',
            'completed_at' => $progress?->completed_at?->toIso8601String(),
            'homework_prompt' => $module->homework_prompt,
            'homework' => $progress?->homework,
            'homework_submitted_at' => $progress?->homework_submitted_at?->toIso8601String(),
        ];
    }
}
