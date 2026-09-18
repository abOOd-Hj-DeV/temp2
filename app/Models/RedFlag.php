<?php

// app/Models/RedFlag.php

namespace App\Models;

use App\Enums\RedFlagPriority;
use App\Enums\RedFlagType;
use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedFlag extends Model
{
    use HasFactory, HasUUID;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'patient_id', 'assessment_id', 'type', 'description',
        'assigned_to', 'status', 'action_taken', 'priority', 'escalated_at',
    ];

    protected $casts = [
        'type' => RedFlagType::class,
        'priority' => RedFlagPriority::class,
        'escalated_at' => 'datetime',
    ];

    /**
     * العلاقة مع المريض
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    /**
     * العلاقة مع المستخدم المسؤول
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    /**
     * تحديد إذا كانت العلامة عاجلة
     */
    public function getIsUrgentAttribute(): bool
    {
        return $this->priority === 'high';
    }

    /**
     * حل العلامة
     */
    public function resolve(string $actionTaken): void
    {
        $this->update([
            'status' => 'resolved',
            'action_taken' => $actionTaken,
        ]);
    }
}
