<?php

// app/Models/Therapist.php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Builder;
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

    public function blockedPeriods(): HasMany
    {
        return $this->hasMany(TherapistBlockedPeriod::class, 'therapist_id', 'user_id');
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
        return $this->reservedClients()->count() < $this->clients_limit
            && $this->isBookable();
    }

    public function isBookable(): bool
    {
        return $this->approval_status === ApprovalStatus::APPROVED && $this->user?->is_active === true;
    }

    /** Current assignments and future/in-progress appointments, counted once per patient. */
    public function reservedClients(): Builder
    {
        return self::reservedClientsFor($this->user_id);
    }

    public function scopeAcceptingClients(Builder $query): Builder
    {
        return $query->where('approval_status', ApprovalStatus::APPROVED->value)
            ->whereHas('user', fn ($users) => $users->where('is_active', true))
            ->where('clients_limit', '>', self::reservedClientsFor('therapists.user_id', column: true)->selectRaw('COUNT(*)'));
    }

    private static function reservedClientsFor(string $therapistId, bool $column = false): Builder
    {
        $cutoff = now('UTC')->subMinutes(TherapySession::durationMinutes());
        $where = $column ? 'whereColumn' : 'where';
        $reservations = TherapySession::query()->{$where}('therapist_id', $therapistId)
            ->whereIn('status', [SessionStatus::PENDING->value, SessionStatus::CONFIRMED->value])
            ->where(function ($query) use ($cutoff) {
                $query->whereDate('session_date', '>', $cutoff->toDateString())
                    ->orWhere(fn ($sameDay) => $sameDay->whereDate('session_date', $cutoff->toDateString())
                        ->where('session_time', '>', $cutoff->format('H:i:s')));
            });

        return Patient::where(function ($query) use ($reservations, $where, $therapistId) {
            $query->{$where}('patients.therapist_id', $therapistId)
                ->orWhereIn('user_id', $reservations->select('patient_id'));
        });
    }

    public function syncClientsCount(): void
    {
        $this->update(['clients_count' => $this->reservedClients()->count()]);
    }
}
