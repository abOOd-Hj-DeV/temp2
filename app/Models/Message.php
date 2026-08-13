<?php
// app/Models/Message.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'sender_id', 'receiver_id', 'content',
        'file_path', 'timestamp', 'is_read'
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'is_read' => 'boolean',
    ];

    /**
     * العلاقة مع المرسل
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * العلاقة مع المستقبل
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * تحديد إذا كانت الرسالة حديثة (آخر 5 دقائق)
     */
    public function getIsRecentAttribute(): bool
    {
        return $this->timestamp->gt(now()->subMinutes(5));
    }
}
