<?php

// app/Models/Therapist.php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Therapist extends Model
{
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'full_name', 'specialty', 'country', 'languages',
        'license_file_path', 'bio', 'rating', 'availability',
        'approval_status', 'clients_count', 'clients_limit',
    ];

    protected $casts = [
        'languages' => 'array',
        'availability' => 'array',
        'rating' => 'float',
        'clients_count' => 'integer',
        'clients_limit' => 'integer',
        'approval_status' => ApprovalStatus::class,
    ];

    /**
     * العلاقة مع المستخدم
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * العلاقة مع المرضى
     */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'therapist_id', 'user_id');
    }

    /**
     * العلاقة مع الجلسات
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(WalletWithdrawal::class, 'therapist_id', 'user_id');
    }

    public function clientNotes(): HasMany
    {
        return $this->hasMany(TherapistClientNote::class, 'therapist_id', 'user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TherapySession::class, 'therapist_id', 'user_id');
    }

    /**
     * التحقق إذا كان المعالج متاح لاستقبال مرضى جدد
     */
    public function getCanAcceptNewClientsAttribute(): bool
    {
        return $this->clients_count < $this->clients_limit
            && $this->approval_status === ApprovalStatus::APPROVED;
    }
}
