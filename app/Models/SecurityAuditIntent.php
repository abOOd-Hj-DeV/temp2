<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SecurityAuditIntent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'credential_generation' => 'integer', 'attempts' => 'integer',
        'occurred_at' => 'datetime', 'delivered_at' => 'datetime', 'next_attempt_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $intent): void {
            if ($intent->isDirty(['id', 'user_id', 'family_id', 'action', 'entity_id', 'credential_generation', 'occurred_at'])) {
                throw new LogicException('Security audit intent payload is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Security audit intent payload is immutable.'));
    }
}
