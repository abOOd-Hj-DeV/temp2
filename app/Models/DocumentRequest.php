<?php

// app/Models/DocumentRequest.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequest extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'doc_type', 'file_path',
        'reason', 'status', 'reviewer_id', 'timestamp',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    /**
     * العلاقة مع المستخدم
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * العلاقة مع المراجع
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * الموافقة على المستند
     */
    public function approve(string $reviewerId): void
    {
        $this->update([
            'status' => 'approved',
            'reviewer_id' => $reviewerId,
        ]);
    }

    /**
     * رفض المستند
     */
    public function reject(string $reviewerId, ?string $reason = null): void
    {
        $this->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reason' => $reason ?: $this->reason,
        ]);
    }
}
