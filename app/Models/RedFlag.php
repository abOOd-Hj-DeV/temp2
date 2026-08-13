<?php
// app/Models/RedFlag.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedFlag extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'patient_id', 'type', 'description', 'assigned_to',
        'status', 'action_taken', 'priority'
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
