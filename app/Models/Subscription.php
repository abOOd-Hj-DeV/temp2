<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory, HasUUID;

    protected $fillable = [
        'patient_id', 'type', 'package_id', 'sessions_total', 'duration_days', 'daily_sessions_quota',
        'start_date', 'end_date', 'price', 'payment_proof_path', 'verification_status', 'content', 'therapist_id',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected $casts = [
        'sessions_total' => 'integer',
        'duration_days' => 'integer',
        'daily_sessions_quota' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'price' => 'decimal:2',
        'content' => 'array',
        'cancelled_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TherapySession::class, 'subscription_id');
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'therapist_id', 'user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'subscription_id');
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->verification_status === 'approved'
            && $this->cancelled_at === null
            && $this->end_date !== null
            && $this->end_date->gte(now()->startOfDay());
    }
}
