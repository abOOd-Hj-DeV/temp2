<?php

namespace App\Services\Patient;

use App\Models\IdempotencyKey;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * GDPR-style erasure that keeps the clinical and financial record intact:
 * personal identifiers on users/patients are scrubbed and the account is
 * permanently disabled, while sessions, payments, assessments and red
 * flags remain linked to the (now anonymous) id for audit purposes.
 *
 * Free text written about the patient (session summaries, payment notes,
 * switch reasons, therapist notes, red-flag text) is kept for clinical
 * continuity but redacted of direct identifiers: the person's name, e-mail
 * addresses and phone numbers. Free text written by the patient (mood notes)
 * is cleared outright; the scores stay.
 *
 * Files the person uploaded (payment proofs, licence documents) are removed
 * from the uploads disk inside the same transaction, so a storage failure
 * rolls the erasure back and the account is retried on the next run.
 */
class AccountAnonymizer
{
    public const REDACTED = '[redacted]';

    /** table => [owner column, free-text columns] */
    private const FREE_TEXT = [
        'therapy_sessions' => ['patient_id', ['summary']],
        'therapist_switches' => ['patient_id', ['reason']],
        'therapist_client_notes' => ['patient_id', ['body']],
        'red_flags' => ['patient_id', ['description', 'action_taken']],
    ];

    public function __construct(private AuditLogService $audit) {}

    public function anonymize(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($user->anonymized_at !== null) {
                return;
            }

            $identifiers = array_filter([
                $user->name,
                $user->email,
                $user->whatsapp_number,
                $user->patient?->full_name,
            ]);

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
            IdempotencyKey::where('user_id', $user->id)->delete();

            $this->redactFreeText($user->id, $identifiers);
            DB::table('mood_logs')->where('patient_id', $user->id)->whereNotNull('notes')->update(['notes' => null]);
            $this->purgeOwnedFiles($user);

            $this->audit->record($user, AuditLogService::ACCOUNT_ANONYMIZED, $user->id);
        });
    }

    /**
     * Delete every upload owned by the person from the uploads disk. Payment
     * rows keep their (non-identifying, generated) path so the financial
     * record survives; the licence path is cleared. A file that still exists
     * after delete() aborts the transaction so nothing is marked anonymized
     * while an identifying file remains.
     */
    private function purgeOwnedFiles(User $user): void
    {
        $disk = Storage::disk(config('sakina.uploads_disk', 'local'));

        $paths = DB::table('payments')
            ->where($this->paymentsOwnedBy($user->id))
            ->pluck('proof_file_path')
            ->push($user->therapist?->license_file_path)
            ->map(fn ($path) => trim((string) $path))
            ->filter(fn (string $path) => $path !== '')
            ->unique();

        foreach ($paths as $path) {
            if (str_contains($path, '..')) {
                throw new \RuntimeException('Owned upload has an unsafe path; anonymization aborted.');
            }

            if (! $disk->exists($path)) {
                continue;
            }

            if (! $disk->delete($path) || $disk->exists($path)) {
                throw new \RuntimeException("Owned upload [{$path}] could not be deleted; anonymization aborted.");
            }
        }

        if ($user->therapist?->license_file_path !== null) {
            $user->therapist->forceFill(['license_file_path' => null])->save();
        }
    }

    private function paymentsOwnedBy(string $userId): \Closure
    {
        return fn ($q) => $q->whereIn('subscription_id', DB::table('subscriptions')->select('id')->where('patient_id', $userId))
            ->orWhereIn('therapy_session_id', DB::table('therapy_sessions')->select('id')->where('patient_id', $userId));
    }

    private function redactFreeText(string $userId, array $identifiers): void
    {
        $tables = self::FREE_TEXT;
        $tables['payments'] = [$this->paymentsOwnedBy($userId), ['note']];

        foreach ($tables as $table => [$owner, $columns]) {
            $query = DB::table($table);
            $owner instanceof \Closure ? $query->where($owner) : $query->where($owner, $userId);
            $rows = $query->get(array_merge(['id'], $columns));

            foreach ($rows as $row) {
                $update = [];

                foreach ($columns as $column) {
                    $original = $row->{$column};
                    $redacted = $original === null ? null : self::redact($original, $identifiers);

                    if ($redacted !== $original) {
                        $update[$column] = $redacted;
                    }
                }

                if ($update !== []) {
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            }
        }
    }

    /**
     * Strip direct identifiers from free text: known identifier strings,
     * e-mail addresses and phone-number-like digit runs.
     */
    public static function redact(string $text, array $identifiers = []): string
    {
        foreach ($identifiers as $identifier) {
            $identifier = trim((string) $identifier);
            if (mb_strlen($identifier) >= 3) {
                $text = str_ireplace($identifier, self::REDACTED, $text);
            }
        }

        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', self::REDACTED, $text);

        return preg_replace('/\+?\d[\d\s\-().]{6,}\d/', self::REDACTED, $text);
    }
}
