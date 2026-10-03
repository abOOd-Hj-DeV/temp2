<?php

namespace App\Services\Files;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Patients first, then other owners (id within each group), before domain rows/bytes. */
class AccountFileFence
{
    public static function lock(array $ids, bool $requireActive = true): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $users = User::whereIn('id', $ids)
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [UserRole::PATIENT->value])
            ->orderBy('id')->lockForUpdate()->get();
        if ($users->count() !== count($ids)) {
            throw new AccessDeniedHttpException('Account is unavailable for file operations.');
        }
        foreach ($users as $user) {
            if (($requireActive && ! $user->is_active) || self::erasing($user->id, $user)) {
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
