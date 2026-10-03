<?php

namespace App\Models;

use App\Casts\ClinicalEncrypted;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private clinical note written by a therapist about one of their clients.
 * Never exposed to the patient.
 */
class TherapistClientNote extends Model
{
    use HasUuids;

    protected $fillable = ['therapist_id', 'patient_id', 'session_id', 'body'];

    protected $casts = ['body' => ClinicalEncrypted::class];

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'therapist_id', 'user_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'session_id');
    }
}
