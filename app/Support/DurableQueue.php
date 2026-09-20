<?php

namespace App\Support;

/**
 * The `sync` queue runs jobs inside the request and cannot retry them. Work
 * that must survive a failure is parked on the configured durable connection
 * whenever the default connection is `sync`.
 */
final class DurableQueue
{
    public static function isSyncDefault(): bool
    {
        return config('queue.default') === 'sync';
    }

    /**
     * Connection to persist retries on, or null when the default connection
     * already retries (a real worker) or no durable alternative exists.
     */
    public static function fallbackConnection(): ?string
    {
        $connection = (string) config('sakina.durable_queue_connection', 'database');

        if ($connection === '' || $connection === 'sync' || config("queue.connections.{$connection}") === null) {
            return null;
        }

        return $connection;
    }
}
