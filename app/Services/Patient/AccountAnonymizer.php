<?php

namespace App\Services\Patient;

use App\Models;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Notifications\NotificationOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Disable and scrub first, commit a resumable file purge plan, then erase files.
 * Clinical/financial rows and append-only audit remain pseudonymous records;
 * this is not a claim of irreversible legal anonymization of those records.
 */
class AccountAnonymizer
{
    public const REDACTED = '[redacted]';

    public function __construct(private AuditLogService $audit) {}

    public function anonymize(User $user): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Erasure must run outside an enclosing transaction so irreversible file deletion cannot roll back account disabling.');
        }
        $plan = DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->anonymized_at !== null) {
                return null;
            }
            $existing = DB::table('clinical_erasure_plans')->where('user_id', $user->id)->first();
            if ($existing) {
                return $existing;
            }
            $identifiers = array_filter([$user->name, $user->email, $user->whatsapp_number, $user->patient?->full_name]);
            app(NotificationOutboxService::class)->discardForRecipient($user);
            $conversations = Models\Conversation::where('patient_id', $user->id)->orWhere('therapist_id', $user->id)->get();
            $messageQuery = Models\Message::where(function ($q) use ($user, $conversations) {
                $q->whereIn('conversation_id', $conversations->pluck('id'))->orWhere('sender_id', $user->id)->orWhere('receiver_id', $user->id);
            });
            $paths = DB::table('payments')->where($this->paymentsOwnedBy($user->id))->pluck('proof_file_path')
                ->merge(DB::table('subscriptions')->where('patient_id', $user->id)->pluck('payment_proof_path'))
                ->merge(DB::table('supports')->where('user_id', $user->id)->pluck('file_path'))
                ->merge(DB::table('document_requests')->where('user_id', $user->id)->pluck('file_path'))
                ->merge((clone $messageQuery)->pluck('file_path'))
                ->push($user->therapist?->license_file_path)->filter()->unique()->values()->all();
            $directories = ["uploads/{$user->id}", "payment-proofs/{$user->id}", "licenses/{$user->id}"];
            foreach ($conversations as $conversation) {
                $directories[] = "chat/{$conversation->id}";
            }
            DB::table('clinical_erasure_plans')->insert([
                'user_id' => $user->id, 'paths' => json_encode($paths), 'directories' => json_encode($directories),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $user->revokeAllTokens();
            $user->forceFill([
                'name' => 'Deleted user', 'email' => "deleted_{$user->id}@anonymized.invalid",
                'whatsapp_number' => '+000'.substr(preg_replace('/\D/', '', $user->id), 0, 12),
                'password' => Hash::make(Str::random(40)), 'is_active' => false,
                // The existing scheduled job picks up unfinished purges again.
                'deletion_scheduled_at' => now(),
            ])->save();
            $user->patient?->forceFill(['full_name' => 'Anonymous patient', 'therapist_id' => null])->save();
            $user->notifications()->delete();
            Models\IdempotencyKey::where('user_id', $user->id)->delete();
            DB::table('notification_logs')->where('user_id', $user->id)->delete();
            Models\ClinicalNotificationEvent::where('patient_id', $user->id)->delete();
            $this->scrubRecords($user->id, $identifiers);
            $messageQuery->delete();
            foreach ($conversations as $conversation) {
                $conversation->delete();
            }

            return DB::table('clinical_erasure_plans')->where('user_id', $user->id)->first();
        });
        if ($plan === null) {
            return;
        }
        $disk = Storage::disk(config('sakina.uploads_disk', 'local'));
        foreach (json_decode($plan->paths, true, 512, JSON_THROW_ON_ERROR) as $path) {
            $this->assertSafePath($path);
            if ($disk->exists($path) && (! $disk->delete($path) || $disk->exists($path))) {
                throw new \RuntimeException('Owned file could not be erased; account remains disabled and purge is retryable.');
            }
        }
        foreach (json_decode($plan->directories, true, 512, JSON_THROW_ON_ERROR) as $directory) {
            $this->assertSafePath($directory);
            if (($disk->exists($directory) || $disk->allFiles($directory) !== [])
                && (! $disk->deleteDirectory($directory) || $disk->allFiles($directory) !== [])) {
                throw new \RuntimeException('Owned directory could not be erased; account remains disabled and purge is retryable.');
            }
        }
        DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->anonymized_at !== null) {
                return;
            }
            DB::table('supports')->where('user_id', $user->id)->update(['file_path' => null]);
            DB::table('document_requests')->where('user_id', $user->id)->update(['file_path' => null, 'original_name' => null, 'mime_type' => null]);
            if ($user->therapist) {
                $user->therapist->forceFill(['license_file_path' => null])->save();
            }
            $user->forceFill(['anonymized_at' => now(), 'deletion_scheduled_at' => null, 'is_active' => false])->save();
            // Booking's immutable snapshots follow the existing patient-update
            // erasure trigger, only after the irreversible purge has completed.
            // Raw timestamp update is intentional: Eloquent touch may be a no-op
            // when disabling and completion share the same second/frozen clock.
            DB::table('patients')->where('user_id', $user->id)->update(['updated_at' => now()]);
            DB::table('clinical_erasure_plans')->where('user_id', $user->id)->update([
                'completed_at' => now(), 'updated_at' => now(), 'paths' => '[]', 'directories' => '[]',
            ]);
            $this->audit->record($user, AuditLogService::ACCOUNT_ANONYMIZED, $user->id);
        });
    }

    private function scrubRecords(string $userId, array $identifiers): void
    {
        // Model writes honor clinical encryption; no plaintext is written by a raw update.
        foreach ([
            Models\TherapistClientNote::class => ['body'], Models\RedFlag::class => ['description', 'action_taken'],
            Models\TherapistSwitch::class => ['reason'], Models\SessionRecommendation::class => ['note'],
        ] as $model => $columns) {
            $this->redactModels($model::where('patient_id', $userId), $columns, $identifiers);
        }
        $this->redactModels(Models\TherapySession::where('patient_id', $userId), ['summary'], $identifiers);
        $this->redactModels(Models\Payment::where($this->paymentsOwnedBy($userId)), ['note'], $identifiers);
        $this->redactModels(Models\Subscription::where('patient_id', $userId), ['content', 'cancellation_reason'], $identifiers);
        $this->redactModels(Models\DocumentRequest::where('user_id', $userId), ['reason', 'review_note', 'original_name'], $identifiers);
        $this->redactModels(Models\TherapistContent::where(function ($q) use ($userId) {
            $q->where('patient_id', $userId)->orWhereHas('assignedPatients', fn ($p) => $p->where('patients.user_id', $userId));
        }), ['title', 'body', 'url'], $identifiers);
        Models\ParallelLayer::where('patient_id', $userId)->eachById(function ($layer) {
            $layer->update(['content' => ['redacted' => true], 'edit_log' => array_map(function ($entry) {
                $entry['details'] = [];

                return $entry;
            }, $layer->edit_log ?? [])]);
        });
        DB::table('therapist_content_assignments')->where('patient_id', $userId)->delete();
        Models\TherapistContent::where('patient_id', $userId)->lockForUpdate()->eachById(function ($item) {
            $item->update(['patient_id' => $item->assignedPatients()->value('patients.user_id')]);
        });
        Models\MoodLog::where('patient_id', $userId)->update(['notes' => null]);
        Models\PatientModule::where('patient_id', $userId)->update(['homework' => null, 'homework_submitted_at' => null]);
        Models\SafetyPlan::where('patient_id', $userId)->delete();
        Models\Review::where('patient_id', $userId)->update(['comment' => null]);
        $tickets = Models\Support::where('user_id', $userId)->get();
        foreach ($tickets as $ticket) {
            $ticket->update(['subject' => self::REDACTED, 'description' => self::REDACTED]);
        }
        Models\SupportReply::whereIn('support_id', $tickets->pluck('id'))->orWhere('user_id', $userId)
            ->eachById(fn ($reply) => $reply->update(['body' => self::REDACTED]));
    }

    private function redactModels($query, array $columns, array $identifiers): void
    {
        $query->lockForUpdate()->eachById(function ($row) use ($columns, $identifiers) {
            foreach ($columns as $column) {
                $row->{$column} = $this->redactValue($row->{$column}, $identifiers);
            }
            $row->save();
        });
    }

    private function redactValue(mixed $value, array $identifiers): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[is_string($key) ? self::redact($key, $identifiers) : $key] = $this->redactValue($item, $identifiers);
            }

            return $result;
        }

        return is_string($value) ? self::redact($value, $identifiers) : $value;
    }

    private function assertSafePath(string $path): void
    {
        if (! preg_match('#^(uploads|payment-proofs|licenses|chat)/[^\x00\\\\]+$#', $path)) {
            throw new \RuntimeException('Unsafe owned file path; manual review required.');
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw new \RuntimeException('Unsafe owned file path; manual review required.');
            }
        }
    }

    private function paymentsOwnedBy(string $userId): \Closure
    {
        return fn ($q) => $q->whereIn('subscription_id', DB::table('subscriptions')->select('id')->where('patient_id', $userId))
            ->orWhereIn('therapy_session_id', DB::table('therapy_sessions')->select('id')->where('patient_id', $userId));
    }

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
