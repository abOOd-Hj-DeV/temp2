<?php

namespace App\Services\Therapist;

use App\Models\ParallelLayer;
use App\Models\Therapist;
use App\Services\AuditLogService;
use Illuminate\Support\Str;

/**
 * The "parallel layer": a free-form working document the therapist keeps
 * per client, parallel to the fixed programme (plan adjustments, notes the
 * patient-facing program doesn't carry). One layer per (therapist, client);
 * every save appends an entry to its edit_log.
 */
class ParallelLayerService
{
    public function __construct(
        private TherapistClientService $clients,
        private AuditLogService $audit,
    ) {}

    public function get(Therapist $therapist, string $patientId): ?ParallelLayer
    {
        $this->clients->requireClient($therapist, $patientId);

        return ParallelLayer::where('therapist_id', $therapist->user_id)
            ->where('patient_id', $patientId)
            ->first();
    }

    public function save(Therapist $therapist, string $patientId, array $content): ParallelLayer
    {
        $patient = $this->clients->requireClient($therapist, $patientId);

        $layer = ParallelLayer::where('therapist_id', $therapist->user_id)
            ->where('patient_id', $patient->user_id)
            ->first();

        if (! $layer) {
            $layer = ParallelLayer::create([
                'id' => (string) Str::uuid(),
                'therapist_id' => $therapist->user_id,
                'patient_id' => $patient->user_id,
                'content' => $content,
            ]);
            $layer->addEditLog('created', []);
        } else {
            $layer->update(['content' => $content]);
            $layer->addEditLog('updated', []);
        }

        $this->audit->record($therapist->user, AuditLogService::PARALLEL_LAYER_SAVED, $layer->id);

        return $layer->refresh();
    }

    public function toArray(ParallelLayer $layer): array
    {
        return [
            'id' => $layer->id,
            'patient_id' => $layer->patient_id,
            'content' => $layer->content,
            'edit_log' => $layer->edit_log ?? [],
            'updated_at' => $layer->updated_at?->toISOString(),
        ];
    }
}
