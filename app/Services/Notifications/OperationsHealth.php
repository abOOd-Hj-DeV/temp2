<?php

namespace App\Services\Notifications;

use App\Models\NotificationOutbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

class OperationsHealth
{
    public function ready(): bool
    {
        $key = 'ops:readiness:'.Str::uuid();

        try {
            DB::select('SELECT 1');
            // Check the migration used by critical delivery, not just connectivity.
            NotificationOutbox::query()->limit(1)->exists();
            Cache::put($key, 'ok', 10);
            $cacheReady = Cache::get($key) === 'ok';
            Cache::forget($key);

            return $cacheReady && $this->configurationValid() && (! config('operations.require_heartbeats') ||
                ($this->heartbeatFresh('worker') && $this->heartbeatFresh('scheduler')));
        } catch (\Throwable) {
            Log::warning('Readiness prerequisites unavailable');

            return false;
        }
    }

    public function configurationValid(): bool
    {
        if (! app()->isProduction()) {
            return true;
        }

        return ! config('app.debug') && filled(config('app.key'))
            && in_array(config('queue.default'), ['redis', 'database'], true)
            && in_array(config('cache.default'), ['redis', 'database'], true)
            && config('broadcasting.default') === 'reverb'
            && filled(config('broadcasting.connections.reverb.key'))
            && filled(config('broadcasting.connections.reverb.secret'))
            && filled(config('broadcasting.connections.reverb.app_id'))
            && (config('sakina.uploads_disk') !== 's3' || (
                filled(config('filesystems.disks.s3.bucket')) && filled(config('filesystems.disks.s3.region'))
                && class_exists(AwsS3V3Adapter::class)
            ));
    }

    public function heartbeat(string $role): void
    {
        Cache::put($this->heartbeatKey($role), now()->timestamp, max(600, (int) config('operations.heartbeat_max_age_seconds') * 2));
    }

    public function heartbeatFresh(string $role): bool
    {
        try {
            $stamp = Cache::get($this->heartbeatKey($role));

            return is_int($stamp) && $stamp <= now()->timestamp && now()->timestamp - $stamp <= (int) config('operations.heartbeat_max_age_seconds');
        } catch (\Throwable) {
            return false;
        }
    }

    public function diagnostics(): array
    {
        $result = [
            'ready' => $this->ready(),
            'worker_fresh' => $this->heartbeatFresh('worker'),
            'scheduler_fresh' => $this->heartbeatFresh('scheduler'),
        ];

        try {
            $result += [
                'outbox_pending' => NotificationOutbox::whereNull('completed_at')->count(),
                'outbox_overdue' => NotificationOutbox::whereNull('completed_at')->where('created_at', '<=', now()->subSeconds((int) config('operations.outbox_alert_age_seconds')))->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'queue_size' => Queue::size(),
                'local_storage_writable' => is_writable(storage_path('app')) && is_writable(storage_path('logs')),
            ];
        } catch (\Throwable) {
            $result['diagnostics_unavailable'] = true;
        }

        return $result;
    }

    private function heartbeatKey(string $role): string
    {
        if (! in_array($role, ['worker', 'scheduler'], true)) {
            throw new \InvalidArgumentException('Unknown process role.');
        }

        return config('operations.heartbeat_prefix').':'.$role;
    }
}
