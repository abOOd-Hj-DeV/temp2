<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportReply extends Model
{
    use HasUUID;

    protected $fillable = ['support_id', 'user_id', 'is_staff', 'body'];

    protected $casts = ['is_staff' => 'boolean'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Support::class, 'support_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
