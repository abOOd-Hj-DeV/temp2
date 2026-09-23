<?php

// app/Models/Review.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'patient_id', 'therapist_id', 'rating', 'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
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
     * التحقق إذا كان التقييم إيجابي (4-5 نجوم)
     */
    public function getIsPositiveAttribute(): bool
    {
        return $this->rating >= 4;
    }

    /**
     * التحقق إذا كان التقييم سلبي (1-2 نجوم)
     */
    public function getIsNegativeAttribute(): bool
    {
        return $this->rating <= 2;
    }
}
