<?php

// app/Models/ParallelLayer.php

namespace App\Models;

use App\Casts\ClinicalEncrypted;
use App\Services\Patient\ClinicalMutationFence;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParallelLayer extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'patient_id', 'therapist_id', 'content', 'edit_log',
    ];

    protected $casts = [
        'content' => ClinicalEncrypted::class.':array',
        'edit_log' => ClinicalEncrypted::class.':array',
    ];

    /**
     * العلاقة مع المريض
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    /**
     * العلاقة مع المعالج
     */
    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'therapist_id', 'user_id');
    }

    /**
     * إضافة سجل تعديل
     */
    public function addEditLog(string $action, array $details): void
    {
        ClinicalMutationFence::run($this->patient_id, function () use ($action, $details) {
            $fresh = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $log = $fresh->edit_log ?? [];
            $log[] = [
                'action' => $action,
                'details' => $details,
                'timestamp' => now()->toISOString(),
                'therapist_id' => $this->therapist_id,
            ];

            $fresh->update(['edit_log' => $log]);
            $this->setRawAttributes($fresh->getAttributes(), true);
        });
    }
}
