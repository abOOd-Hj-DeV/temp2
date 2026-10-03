<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;

class NotificationOutbox extends Model
{
    use HasUUID;

    protected $table = 'ops_notification_outbox';

    protected $guarded = [];

    protected $hidden = ['payload', 'claim_token'];

    protected $casts = [
        'available_at' => 'datetime',
        'lease_until' => 'datetime',
        'completed_at' => 'datetime',
        'attempts' => 'integer',
    ];
}
