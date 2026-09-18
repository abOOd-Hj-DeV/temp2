<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletWithdrawal extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'therapist_id', 'amount', 'status', 'payout_details', 'note', 'reviewer_id', 'reviewed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payout_details' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class, 'therapist_id', 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
