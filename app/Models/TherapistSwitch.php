<?php

// app/Models/TherapistSwitch.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TherapistSwitch extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'patient_id', 'old_therapist_id', 'new_therapist_id',
        'subscription_id', 'reason', 'timestamp', 'status',
        'therapist_decision', 'therapist_decided_at', 'decided_by', 'decided_at',
        'cancelled_session_ids',
    ];

    protected $casts = [
        'cancelled_session_ids' => 'array',
        'timestamp' => 'datetime',
        'therapist_decided_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    /**
     * العلاقة مع المريض
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    /**
     * العلاقة مع المعالج القديم
     */
    public function oldTherapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'old_therapist_id', 'user_id');
    }

    /**
     * العلاقة مع المعالج الجديد
     */
    public function newTherapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'new_therapist_id', 'user_id');
    }

    /**
     * العلاقة مع الاشتراك
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * الموافقة على تغيير المعالج
     */
    public function approve(): void
    {
        $this->update(['status' => 'approved']);

        // تحديث المعالج للمريض
        $this->patient->update(['therapist_id' => $this->new_therapist_id]);
    }

    /**
     * رفض تغيير المعالج
     */
    public function reject(): void
    {
        $this->update(['status' => 'rejected']);
    }
}
