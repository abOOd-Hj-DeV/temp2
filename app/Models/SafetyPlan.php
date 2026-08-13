<?php
// app/Models/SafetyPlan.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyPlan extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'patient_id', 'contact_info', 'coping_strategies',
        'emergency_contacts', 'warning_signs'
    ];

    protected $casts = [
        'contact_info' => 'array',
    ];

    /**
     * العلاقة مع المريض
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    /**
     * الحصول على جهات الاتصال الطارئة
     */
    public function getEmergencyContactsArray(): array
    {
        return json_decode($this->emergency_contacts, true) ?? [];
    }

    /**
     * الحصول على استراتيجيات التعامل
     */
    public function getCopingStrategiesArray(): array
    {
        return explode("\n", $this->coping_strategies);
    }

    /**
     * الحصول على علامات التحذير
     */
    public function getWarningSignsArray(): array
    {
        return explode("\n", $this->warning_signs);
    }
}
