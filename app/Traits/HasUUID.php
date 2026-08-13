<?php
// app/Traits/HasUUID.php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUUID
{
    /**
     * Boot the trait
     */
    protected static function bootHasUUID(): void
    {
        static::creating(function ($model) {
            // إذا لم يكن هناك ID، قم بإنشاء UUID
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::orderedUuid();
            }
        });
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getKeyType(): string
    {
        return 'string';
    }
}
