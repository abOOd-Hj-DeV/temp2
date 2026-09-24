<?php

// app/Models/Support.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Support extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'type', 'subject', 'description', 'file_path',
        'status', 'assigned_to',
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

    public function replies(): HasMany
    {
        return $this->hasMany(SupportReply::class, 'support_id')->orderBy('created_at');
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
