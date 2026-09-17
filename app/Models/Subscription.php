<?php

namespace App\Models;

use App\Enums\SubscriptionType;
use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory, HasUUID;

    protected $fillable = [
        'patient_id', 'type', 'start_date', 'end_date',
        'price', 'payment_proof_path', 'verification_status', 'content',
    ];

    protected $casts = [
        'type' => SubscriptionType::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'price' => 'decimal:2',
        'content' => 'array',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'subscription_id');
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->verification_status === 'approved'
            && $this->end_date !== null
            && $this->end_date->gte(now()->startOfDay());
    }
}
