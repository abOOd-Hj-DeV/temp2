<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ClinicalEncrypted implements CastsAttributes
{
    public const PREFIX = 'clinical:v1:';

    public function __construct(private string $type = 'string') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        if (str_starts_with($value, self::PREFIX)) {
            $value = Crypt::decryptString(substr($value, strlen(self::PREFIX)));
        }

        return $this->type === 'array' ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        $plain = $this->type === 'array' ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value;

        return self::PREFIX.Crypt::encryptString($plain);
    }
}
