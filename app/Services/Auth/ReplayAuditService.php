<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\RefreshToken;
use App\Models\SecurityAuditIntent;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

class ReplayAuditService
{
    public function __construct(private AuditLogService $audit) {}

    public function record(User $user, RefreshToken $refresh): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Replay audit intent must share the containment transaction.');
        }
        try {
            DB::transaction(fn () => $this->audit->record($user, AuditLogService::REFRESH_TOKEN_REPLAYED, $user->id, ['family_id' => $refresh->family_id]));

            return;
        } catch (QueryException $exception) {
            $error = $this->redactedError($exception);
        }
        $intent = SecurityAuditIntent::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'family_id' => $refresh->family_id,
            'action' => AuditLogService::REFRESH_TOKEN_REPLAYED, 'entity_id' => $user->id,
            'credential_generation' => $user->credential_version,
            'occurred_at' => now(), 'next_attempt_at' => now()->addMinute(),
            'attempts' => 1, 'last_error' => $error,
        ]);
        DB::afterCommit(fn () => Log::error('Replay audit pending durable intent delivery', ['intent_id' => $intent->id, 'error' => $error]));
    }

    public function deliver(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $intent = SecurityAuditIntent::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($intent->delivered_at !== null) {
                return true;
            }
            $error = null;
            try {
                DB::transaction(function () use ($intent): void {
                    $audit = AuditLog::firstOrCreate(['id' => $intent->id], [
                        'user_id' => $intent->user_id, 'action' => $intent->action,
                        'entity_id' => $intent->entity_id, 'details' => ['family_id' => $intent->family_id],
                        'timestamp' => $intent->occurred_at,
                    ]);
                    if ($audit->user_id !== $intent->user_id || $audit->action !== $intent->action
                        || $audit->entity_id !== $intent->entity_id || $audit->details !== ['family_id' => $intent->family_id]
                        || $audit->timestamp->toDateTimeString() !== $intent->occurred_at->toDateTimeString()) {
                        throw new LogicException('Security audit identifier collision.');
                    }
                });
            } catch (QueryException $exception) {
                $error = $this->redactedError($exception);
            }
            $intent->forceFill([
                'attempts' => $intent->attempts + 1, 'last_error' => $error,
                'delivered_at' => $error === null ? now() : null,
                'next_attempt_at' => now()->addMinute(),
            ])->save();
            if ($error !== null) {
                DB::afterCommit(fn () => Log::error('Replay audit pending durable intent delivery', ['intent_id' => $id, 'error' => $error]));
            }

            return $error === null;
        });
    }

    private function redactedError(QueryException $exception): string
    {
        $state = (string) ($exception->errorInfo[0] ?? '');

        return preg_match('/^[A-Z0-9]{5}$/', $state) ? 'sqlstate:'.$state : 'audit_insert_failed';
    }
}
