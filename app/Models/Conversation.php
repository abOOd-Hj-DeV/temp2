<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Private 1:1 thread between a patient and the therapist assigned to them.
 * Closed once that assignment ends: history stays readable to both
 * participants, nothing new can be sent.
 */
class Conversation extends Model
{
    use HasUUID;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = ['patient_id', 'therapist_id', 'status', 'last_message_at', 'closed_at'];

    protected $casts = [
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'therapist_id', 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isParticipant(User $user): bool
    {
        return $user->id === $this->patient_id || $user->id === $this->therapist_id;
    }

    public function counterpartId(User $user): string
    {
        return $user->id === $this->patient_id ? $this->therapist_id : $this->patient_id;
    }
}
