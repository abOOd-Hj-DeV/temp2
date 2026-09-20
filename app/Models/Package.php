<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasUUID;

    protected $fillable = [
        'code', 'name', 'description', 'price', 'number_of_sessions', 'duration_days',
        'daily_sessions_quota', 'is_published', 'created_by', 'published_by', 'published_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'number_of_sessions' => 'integer',
        'duration_days' => 'integer',
        'daily_sessions_quota' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'package_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
