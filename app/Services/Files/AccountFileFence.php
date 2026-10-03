<?php

namespace App\Services\Files;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Lock before domain rows/bytes so erasure cannot be followed by an in-flight write. */
class AccountFileFence
{
    public static function lock(array $ids, bool $requireActive = true): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        foreach ($ids as $id) {
            $user = User::whereKey($id)->lockForUpdate()->first();
            if (! $user || ($requireActive && ! $user->is_active) || self::erasing($id, $user)) {
                throw new AccessDeniedHttpException('Account is unavailable for file operations.');
            }
        }
    }

    public static function erasing(string $id, ?User $user = null): bool
    {
        $user ??= User::find($id);

        return ! $user || $user->anonymized_at !== null
            || DB::table('clinical_erasure_plans')->where('user_id', $id)->exists();
    }
}
