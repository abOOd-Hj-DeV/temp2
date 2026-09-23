<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chat message. The body is encrypted at rest (APP_KEY); the attachment,
 * if any, lives on the private uploads disk and is only reachable through
 * the authorized download endpoint.
 */
class Message extends Model
{
    use HasFactory, HasUUID;

    public $timestamps = false;

    public const ATTACHMENT_IMAGE = 'image';

    public const ATTACHMENT_AUDIO = 'audio';

    public const ATTACHMENT_FILE = 'file';

    public const ATTACHMENT_TYPES = [self::ATTACHMENT_IMAGE, self::ATTACHMENT_AUDIO, self::ATTACHMENT_FILE];

    protected $fillable = [
        'id', 'conversation_id', 'sender_id', 'receiver_id', 'content',
        'file_path', 'attachment_type', 'attachment_name', 'attachment_mime', 'attachment_size',
        'timestamp', 'is_read', 'read_at',
    ];

    protected $casts = [
        'content' => 'encrypted',
        'attachment_size' => 'integer',
        'timestamp' => 'datetime',
        'read_at' => 'datetime',
        'is_read' => 'boolean',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function hasAttachment(): bool
    {
        return $this->file_path !== null;
    }

    public function getIsRecentAttribute(): bool
    {
        return $this->timestamp->gt(now()->subMinutes(5));
    }
}
