<?php

namespace App\Services\Patient;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * GDPR-style erasure that keeps the clinical and financial record intact:
 * personal identifiers on users/patients are scrubbed and the account is
 * permanently disabled, while sessions, payments, assessments and red
 * flags remain linked to the (now anonymous) id for audit purposes.
 */
class AccountAnonymizer
{
    public function __construct(private AuditLogService $audit) {}

    public function anonymize(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->revokeAllTokens();

            $user->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted_{$user->id}@anonymized.invalid",
                'whatsapp_number' => '+000'.substr(preg_replace('/\D/', '', $user->id), 0, 12),
                'password' => Hash::make(Str::random(40)),
                'is_active' => false,
                'deletion_scheduled_at' => null,
                'anonymized_at' => now(),
            ])->save();

            $user->patient?->forceFill([
                'full_name' => 'Anonymous patient',
                'therapist_id' => null,
            ])->save();

            $user->notifications()->delete();

            $this->audit->record($user, AuditLogService::ACCOUNT_ANONYMIZED, $user->id);
        });
    }
}
