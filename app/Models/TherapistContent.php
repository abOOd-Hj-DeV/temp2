<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TherapistContent extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'therapist_id', 'patient_id', 'title', 'content_type', 'body', 'url',
    ];

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'therapist_id', 'user_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    /** Clients this library item has been shared with. */
    public function assignedPatients(): BelongsToMany
    {
        return $this->belongsToMany(
            Patient::class, 'therapist_content_assignments', 'content_id', 'patient_id', 'id', 'user_id',
        )->withPivot('assigned_at');
    }
}
