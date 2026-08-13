<?php
// app/Models/Session.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Session extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'patient_id', 'therapist_id', 'session_date', 'session_time',
        'medium', 'price', 'status', 'link', 'summary', 'is_initial',
        'payment_status', 'reminder_sent'
    ];

    protected $casts = [
        'session_date' => 'date',
        'price' => 'decimal:2',
        'is_initial' => 'boolean',
        'reminder_sent' => 'boolean',
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
     * التحقق إذا كانت الجلسة مجانية (أولية + اشتراك نشط)
     */
    public function getIsFreeAttribute(): bool
    {
        return $this->is_initial && $this->patient->subscriptions()
            ->where('verification_status', 'approved')
            ->where('end_date', '>=', now())
            ->exists();
    }
}
