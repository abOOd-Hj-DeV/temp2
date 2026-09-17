<?php

// app/Models/Payment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'subscription_id', 'amount', 'proof_file_path',
        'reviewer_id', 'status', 'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * العلاقة مع الاشتراك
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * العلاقة مع المراجع
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * الموافقة على الدفع
     */
    public function approve(?string $note = null, ?string $reviewerId = null): void
    {
        $this->update([
            'status' => 'approved',
            'note' => $note,
            'reviewer_id' => $reviewerId,
        ]);
    }

    /**
     * رفض الدفع
     */
    public function reject(?string $note = null, ?string $reviewerId = null): void
    {
        $this->update([
            'status' => 'rejected',
            'note' => $note,
            'reviewer_id' => $reviewerId,
        ]);
    }
}
