<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TherapistBlockedPeriod extends Model
{
    use HasUUID;

    protected $fillable = ['therapist_id', 'start_date', 'end_date', 'reason'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'therapist_id', 'user_id');
    }

    /** Whether a therapist-local calendar date (Y-m-d) falls inside the period. */
    public function coversDate(string $localDate): bool
    {
        return $localDate >= $this->start_date->toDateString() && $localDate <= $this->end_date->toDateString();
    }
}
