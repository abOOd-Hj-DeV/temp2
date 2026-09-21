<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Session times are stored as UTC wall-clock (`session_date` + `session_time`).
 * Every conversion between a user's IANA zone and that storage goes through
 * here so the contract lives in one place.
 */
final class SessionClock
{
    public const UTC = 'UTC';

    /** Interpret a local date + "HH:MM" in $timezone as a UTC instant. */
    public static function toUtc(string $date, string $time, string $timezone): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $date.' '.substr($time, 0, 5), $timezone)->utc();
    }

    /** UTC instant of a stored session (or reschedule) date/time pair. */
    public static function fromStored(\DateTimeInterface|string $date, string $time): Carbon
    {
        $day = $date instanceof \DateTimeInterface ? Carbon::instance($date)->toDateString() : substr($date, 0, 10);

        return Carbon::createFromFormat('Y-m-d H:i', $day.' '.substr($time, 0, 5), self::UTC);
    }

    /** @return array{date: string, time: string, timezone: string} */
    public static function localize(Carbon $utc, string $timezone): array
    {
        $local = $utc->copy()->setTimezone($timezone);

        return ['date' => $local->toDateString(), 'time' => $local->format('H:i'), 'timezone' => $timezone];
    }

    /** UTC bounds of one calendar day in $timezone: [start, end). */
    public static function dayBounds(string $date, string $timezone): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();

        return [$start->copy()->utc(), $start->copy()->addDay()->utc()];
    }
}
