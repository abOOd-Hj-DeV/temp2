<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasUUID;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'key', 'route', 'request_hash', 'response_code', 'response_body',
        'locked_at', 'completed_at', 'expires_at',
    ];

    // Replayed bodies can echo private payloads (e.g. chat text), so they are
    // stored encrypted like the source records they mirror.
    protected $casts = [
        'response_body' => 'encrypted',
        'locked_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
