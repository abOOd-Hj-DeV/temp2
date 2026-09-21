<?php

namespace App\Services\Program;

use App\Exceptions\ConflictException;
use App\Models\Module;
use App\Models\PatientModule;
use App\Models\Program;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Head Master (clinical_supervisor) / admin management of the self-help
 * programme library patients read from. Content that patients have already
 * started is never deleted from under them.
 */
class ProgramService
{
    public function __construct(private AuditLogService $audit) {}

    public function all(): Collection
    {
        return Program::with(['modules' => fn ($q) => $q->orderBy('order')])
            ->withCount('modules')
            ->orderByDesc('is_core')
            ->orderBy('name')
            ->get();
    }

    public function create(User $actor, array $data): Program
    {
        $program = DB::transaction(fn () => Program::create($data));

        $this->audit->record($actor, AuditLogService::PROGRAM_CREATED, $program->id, $data);

        return $program->load('modules');
    }

    public function update(User $actor, Program $program, array $data): Program
    {
        DB::transaction(fn () => $program->update($data));

        $this->audit->record($actor, AuditLogService::PROGRAM_UPDATED, $program->id, $data);

        return $program->load(['modules' => fn ($q) => $q->orderBy('order')]);
    }

    public function delete(User $actor, Program $program): void
    {
        DB::transaction(function () use ($program) {
            if (PatientModule::whereIn('module_id', $program->modules()->select('id'))->exists()) {
                throw new ConflictException('Patients have progress in this program; it cannot be deleted.');
            }

            $program->delete();
        });

        $this->audit->record($actor, AuditLogService::PROGRAM_DELETED, $program->id, ['name' => $program->name]);
    }

    public function addModule(User $actor, Program $program, array $data): Module
    {
        $module = DB::transaction(function () use ($program, $data) {
            $data['order'] ??= ((int) $program->modules()->max('order')) + 1;

            return $program->modules()->create($data);
        });

        $this->audit->record($actor, AuditLogService::MODULE_CREATED, $module->id, ['program_id' => $program->id, 'title' => $module->title]);

        return $module;
    }

    public function updateModule(User $actor, Module $module, array $data): Module
    {
        DB::transaction(fn () => $module->update($data));

        $this->audit->record($actor, AuditLogService::MODULE_UPDATED, $module->id, ['program_id' => $module->program_id] + $data);

        return $module;
    }

    public function deleteModule(User $actor, Module $module): void
    {
        DB::transaction(function () use ($module) {
            if (PatientModule::where('module_id', $module->id)->exists()) {
                throw new ConflictException('Patients have progress in this module; it cannot be deleted.');
            }

            $module->delete();
        });

        $this->audit->record($actor, AuditLogService::MODULE_DELETED, $module->id, ['program_id' => $module->program_id, 'title' => $module->title]);
    }

    public function toArray(Program $program): array
    {
        return [
            'id' => $program->id,
            'name' => $program->name,
            'description' => $program->description,
            'is_core' => (bool) $program->is_core,
            'modules_count' => $program->modules_count ?? $program->modules->count(),
            'modules' => $program->modules->map(fn (Module $m) => $this->moduleToArray($m))->values()->all(),
            'created_at' => $program->created_at?->toISOString(),
            'updated_at' => $program->updated_at?->toISOString(),
        ];
    }

    public function moduleToArray(Module $module): array
    {
        return [
            'id' => $module->id,
            'program_id' => $module->program_id,
            'title' => $module->title,
            'description' => $module->description,
            'content_type' => $module->content_type,
            'exercise' => $module->exercise,
            'tracking_tools' => $module->tracking_tools,
            'order' => (int) $module->order,
        ];
    }
}
