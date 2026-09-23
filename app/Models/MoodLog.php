<?php

// app/Models/MoodLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoodLog extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'patient_id', 'score', 'anxiety', 'energy', 'sleep_hours',
        'activity_level', 'notes', 'log_date', 'alert_sent',
    ];

    protected $casts = [
        'score' => 'integer',
        'anxiety' => 'integer',
        'energy' => 'integer',
        'sleep_hours' => 'float',
        'activity_level' => 'integer',
        'log_date' => 'date',
        'alert_sent' => 'boolean',
    ];

    /**
     * العلاقة مع المريض
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    /**
     * التحقق إذا كانت درجة المزاج منخفضة وتحتاج إنذار
     */
    public function requiresAlert(): bool
    {
        return $this->score <= 3; // إذا كانت الدرجة 3 أو أقل
    }
}
