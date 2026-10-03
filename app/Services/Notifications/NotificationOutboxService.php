<?php

namespace App\Services\Notifications;

use App\Jobs\DeliverNotificationOutboxJob;
use App\Models\NotificationOutbox;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationOutboxService
{
    public function stage(ShouldQueue $job, ?string $dedupeKey = null, bool $inline = false): NotificationOutbox
    {
        $row = NotificationOutbox::firstOrCreate(['dedupe_key' => hash('sha256', $dedupeKey ?? (string) Str::uuid())], [
            'payload' => Crypt::encryptString(serialize($job)),
            'available_at' => now(),
        ]);

        DB::afterCommit(function () use ($row, $inline): void {
            if ($inline) {
                $this->execute($row->id);
            } else {
                $this->enqueue($row->id);
            }
        });

        return $row;
    }

    public function replay(int $limit = 100): int
    {
        $ids = NotificationOutbox::whereNull('completed_at')
            ->where('available_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->orderBy('available_at')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            $this->enqueue($id);
        }

        return $ids->count();
    }

    public function enqueue(string $id): void
    {
        $token = $this->claim($id, 'queued');

        if ($token === null) {
            return;
        }

        try {
            app(Dispatcher::class)->dispatch(new DeliverNotificationOutboxJob($id, $token));
        } catch (\Throwable) {
            $this->release($id, $token, 'queue_unavailable');
        }
    }

    public function execute(string $id, ?string $queuedToken = null): void
    {
        $token = $queuedToken;

        if ($token === null) {
            $token = $this->claim($id, 'processing');
        } elseif (NotificationOutbox::whereKey($id)->where('claim_token', $token)
            ->where('status', 'queued')->update(['status' => 'processing']) !== 1) {
            return;
        }

        if ($token === null) {
            return;
        }

        try {
            $row = NotificationOutbox::findOrFail($id);
            $job = unserialize(Crypt::decryptString($row->payload));

            if (! $job instanceof ShouldQueue || ! method_exists($job, 'handle')) {
                throw new \UnexpectedValueException('Invalid outbox work item.');
            }

            app()->call([$job, 'handle']);

            NotificationOutbox::whereKey($id)->where('claim_token', $token)->update([
                'status' => 'completed', 'completed_at' => now(), 'payload' => null,
                'claim_token' => null, 'lease_until' => null, 'last_error' => null,
            ]);
        } catch (\Throwable) {
            $this->release($id, $token, 'delivery_failed');
        }
    }

    private function claim(string $id, string $status): ?string
    {
        $token = (string) Str::uuid();
        $changed = NotificationOutbox::whereKey($id)->whereNull('completed_at')
            ->where('available_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->update([
                'status' => $status, 'claim_token' => $token,
                'lease_until' => now()->addSeconds((int) config('operations.outbox_lease_seconds', 300)),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        return $changed === 1 ? $token : null;
    }

    private function release(string $id, string $token, string $error): void
    {
        $attempts = NotificationOutbox::whereKey($id)->value('attempts') ?? 1;
        NotificationOutbox::whereKey($id)->where('claim_token', $token)->update([
            'status' => 'pending', 'claim_token' => null, 'lease_until' => null,
            'available_at' => now()->addSeconds(min(3600, 30 * (2 ** min(7, $attempts - 1)))),
            'last_error' => $error,
        ]);

        Log::warning('Notification outbox deferred', ['outbox_id' => $id, 'reason' => $error]);
    }
}
