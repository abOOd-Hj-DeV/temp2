<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt record per (event, recipient, channel). Holds routing
 * metadata only: no message body, no phone number.
 */
class NotificationLog extends Model
{
    use HasUUID;

    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const CHANNELS = [self::CHANNEL_IN_APP, self::CHANNEL_WHATSAPP];

    public const STATUSES = [self::STATUS_QUEUED, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_SKIPPED];

    protected $fillable = [
        'user_id', 'channel', 'event', 'event_key', 'status',
        'attempts', 'error', 'context', 'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'context' => 'array',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'attempts' => $this->attempts + 1,
            'error' => null,
            'sent_at' => now(),
        ])->save();
    }

    /** A failed attempt that will be retried: stays queued, keeps the last error. */
    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_QUEUED,
            'attempts' => $this->attempts + 1,
            'error' => mb_substr($error, 0, 500),
        ])->save();
    }

    public function markPermanentlyFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($error, 0, 500),
        ])->save();
    }
}
