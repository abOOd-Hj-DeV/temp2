<?php

// app/Models/DocumentRequest.php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document staff asked a user (currently: a therapist) to provide.
 *
 * requested → submitted → approved
 *                       ↘ rejected → submitted (re-upload) …
 */
class DocumentRequest extends Model
{
    use HasFactory, HasUUID;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED,
    ];

    public const DOC_TYPES = ['license', 'identity', 'certificate', 'payment_proof', 'support_file', 'other'];

    protected $fillable = [
        'id', 'user_id', 'requested_by', 'doc_type', 'file_path', 'original_name', 'mime_type',
        'reason', 'review_note', 'status', 'reviewer_id', 'submitted_at', 'reviewed_at', 'timestamp',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** The therapist may (re)upload while the request is open or was rejected. */
    public function acceptsUpload(): bool
    {
        return in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_REJECTED], true);
    }
}
