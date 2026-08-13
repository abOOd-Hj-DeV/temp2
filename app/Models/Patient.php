<?php
// app/Models/Patient.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use HasFactory;

    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'full_name', 'age', 'gender', 'language',
        'assessment_score', 'safety_flag', 'therapist_id',
        'subscription_id', 'compliance_level'
    ];

    protected $casts = [
        'age' => 'integer',
        'assessment_score' => 'integer',
        'safety_flag' => 'boolean',
    ];

    /**
     * العلاقة مع المستخدم
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * العلاقة مع المعالج
     */
    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'therapist_id', 'user_id');
    }

    /**
     * العلاقة مع الاشتراكات
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'patient_id', 'user_id');
    }

    /**
     * العلاقة مع الجلسات
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'patient_id', 'user_id');
    }

    /**
     * العلاقة مع التقييمات
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'patient_id', 'user_id');
    }

    /**
     * العلاقة مع سجلات المزاج
     */
    public function moodLogs(): HasMany
    {
        return $this->hasMany(MoodLog::class, 'patient_id', 'user_id');
    }
}
