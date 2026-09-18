<?php

namespace App\Models;

use App\Enums\PaymentReviewStatus;
use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, HasUUID;

    protected $fillable = [
        'subscription_id', 'therapy_session_id', 'amount',
        'proof_file_path', 'reviewer_id', 'status', 'note', 'reviewed_at', 'review_reminder_sent_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'status' => PaymentReviewStatus::class,
        'reviewed_at' => 'datetime',
        'review_reminder_sent_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TherapySession::class, 'therapy_session_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
