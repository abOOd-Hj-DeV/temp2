<?php

// app/Models/AuditLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id', 'user_id', 'action', 'entity_id', 'details', 'timestamp',
    ];

    protected $casts = [
        'details' => 'array',
        'timestamp' => 'datetime',
    ];

    /** Append-only: the database rejects UPDATE/DELETE too; fail fast here. */
    protected static function booted(): void
    {
        $reject = fn () => throw new LogicException('audit_logs is append-only.');

        static::updating($reject);
        static::deleting($reject);
    }

    /**
     * العلاقة مع المستخدم
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * تسجيل إجراء جديد
     */
    public static function logAction(
        string $userId,
        string $action,
        ?string $entityId = null,
        ?array $details = null
    ): self {
        return self::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_id' => $entityId,
            'details' => $details,
            'timestamp' => now(),
        ]);
    }
}
