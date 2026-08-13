<?php
// app/Models/Support.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Support extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'type', 'description', 'file_path',
        'status', 'assigned_to'
    ];

    /**
     * العلاقة مع المستخدم الذي فتح التذكرة
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * العلاقة مع المستخدم المسؤول
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * التحقق إذا كانت التذكرة مفتوحة
     */
    public function getIsOpenAttribute(): bool
    {
        return $this->status === 'open';
    }

    /**
     * إغلاق التذكرة
     */
    public function close(): void
    {
        $this->update(['status' => 'closed']);
    }
}
