<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\SessionMedium;
use App\Enums\SessionStatus;
use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TherapySession extends Model
{
    use HasFactory, HasUUID;

    protected $fillable = [
        'patient_id', 'therapist_id', 'session_date', 'session_time',
        'medium', 'price', 'status', 'link', 'summary', 'is_initial',
        'payment_status', 'reminder_sent', 'reminder_1h_sent', 'reminder_attempts', 'reminder_1h_attempts',
        'attendance_confirmed_at', 'report_revision', 'reschedule_date', 'reschedule_time',
        'reschedule_requested_by', 'reschedule_requested_at',
    ];

    protected $casts = [
        'session_date' => 'date',
        'price' => 'decimal:2',
        'is_initial' => 'boolean',
        'reminder_sent' => 'boolean',
        'reminder_1h_sent' => 'boolean',
        'status' => SessionStatus::class,
        'medium' => SessionMedium::class,
        'payment_status' => PaymentStatus::class,
        'attendance_confirmed_at' => 'datetime',
        'report_revision' => 'integer',
        'reschedule_date' => 'date',
        'reschedule_requested_at' => 'datetime',
    ];

    public static function durationMinutes(): int
    {
        return max(1, (int) config('sakina.session_duration_minutes', 60));
    }

    /**
     * Whether two same-day sessions of the configured duration starting at
     * the given H:i times overlap. Back-to-back sessions do not.
     */
    public static function startTimesOverlap(string $a, string $b): bool
    {
        $toMinutes = fn (string $t) => ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2);
        $duration = self::durationMinutes();
        [$startA, $startB] = [$toMinutes($a), $toMinutes($b)];

        return $startA < $startB + $duration && $startB < $startA + $duration;
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'user_id');
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'therapist_id', 'user_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(SessionStatusLog::class, 'session_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'therapy_session_id');
    }
}
