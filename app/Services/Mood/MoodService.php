<?php

namespace App\Services\Mood;

use App\Enums\RedFlagPriority;
use App\Enums\RedFlagType;
use App\Models\MoodLog;
use App\Models\Patient;
use App\Services\RedFlagService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Mood check-ins (1–10). A patient may log as many entries per day as they
 * like; every entry is kept. Day-level views (chart series, low-mood streak)
 * use the most recent entry of each calendar day, so several low check-ins
 * on one day never count as several low days. A streak of low days raises a
 * LOW_MOOD red flag once (alert_sent) so the clinical team is not spammed.
 */
class MoodService
{
    public function __construct(private RedFlagService $redFlags) {}

    public function log(Patient $patient, array $data): array
    {
        $date = $data['log_date'] ?? now($patient->user?->timezone() ?? config('app.timezone', 'UTC'))->toDateString();

        $log = MoodLog::create([
            'id' => (string) Str::uuid(),
            'patient_id' => $patient->user_id,
            'log_date' => $date,
            'score' => (int) $data['score'],
            'anxiety' => $data['anxiety'] ?? null,
            'energy' => $data['energy'] ?? null,
            'sleep_hours' => $data['sleep_hours'] ?? null,
            'activity_level' => $data['activity_level'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $alert = $this->maybeRaiseAlert($patient, $log);

        return [
            'mood' => $this->toArray($log),
            'created' => true,
            'alert_raised' => $alert,
        ];
    }

    /**
     * Every entry of the last N days, newest first — nothing is collapsed.
     */
    public function history(Patient $patient, int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $logs = MoodLog::where('patient_id', $patient->user_id)
            ->whereDate('log_date', '>=', $from->toDateString())
            ->orderByDesc('log_date')
            ->orderByDesc('created_at')
            ->get();

        return [
            'days' => $days,
            'entries' => $logs->map(fn (MoodLog $l) => $this->toArray($l))->all(),
        ];
    }

    /**
     * Last N days as a chart-ready series plus summary stats.
     */
    public function chart(Patient $patient, int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $logs = MoodLog::where('patient_id', $patient->user_id)
            ->whereDate('log_date', '>=', $from->toDateString())
            ->orderBy('log_date')
            ->orderBy('created_at')
            ->get();

        $byDate = $logs->groupBy(fn (MoodLog $l) => $l->log_date->toDateString());
        $series = [];

        for ($d = $from->copy(); $d->lte(now()->startOfDay()); $d->addDay()) {
            $key = $d->toDateString();
            /** @var Collection<int, MoodLog>|null $day */
            $day = $byDate->get($key);
            $log = $day?->last();
            $series[] = [
                'date' => $key,
                'entries' => $day?->count() ?? 0,
                'score' => $log?->score,
                'average_score' => $day === null ? null : round($day->avg('score'), 2),
                'anxiety' => $log?->anxiety,
                'energy' => $log?->energy,
                'sleep_hours' => $log?->sleep_hours,
                'activity_level' => $log?->activity_level,
            ];
        }

        $scores = $logs->pluck('score');

        return [
            'days' => $days,
            'series' => $series,
            'summary' => [
                'entries' => $scores->count(),
                'average' => $scores->isEmpty() ? null : round($scores->avg(), 2),
                'min' => $scores->min(),
                'max' => $scores->max(),
                'average_anxiety' => $this->avgColumn($logs, 'anxiety'),
                'average_energy' => $this->avgColumn($logs, 'energy'),
                'average_sleep_hours' => $this->avgColumn($logs, 'sleep_hours'),
                'average_activity_level' => $this->avgColumn($logs, 'activity_level'),
                'trend' => $this->trend($logs),
                'current_low_streak' => $this->lowStreak($patient),
            ],
        ];
    }

    private function maybeRaiseAlert(Patient $patient, MoodLog $log): bool
    {
        $threshold = (int) config('sakina.mood_alert_threshold', 3);
        $streakNeeded = (int) config('sakina.mood_alert_streak', 3);

        if ($log->score > $threshold || $log->alert_sent) {
            return false;
        }

        $streak = $this->lowStreak($patient);

        if ($streak < $streakNeeded) {
            return false;
        }

        $alreadyFlagged = MoodLog::where('patient_id', $patient->user_id)
            ->where('alert_sent', true)
            ->whereDate('log_date', '>=', now()->subDays($streak)->toDateString())
            ->exists();

        if ($alreadyFlagged) {
            return false;
        }

        $this->redFlags->createFromMood(
            $patient,
            RedFlagType::LOW_MOOD,
            RedFlagPriority::MEDIUM,
            sprintf('Mood score ≤ %d for %d consecutive days (latest %d/10 on %s).',
                $threshold, $streakNeeded, $log->score, $log->log_date->toDateString()),
        );

        $log->update(['alert_sent' => true]);

        return true;
    }

    /** Today or yesterday — anything older is history, not a live signal. */
    private function isCurrent(Carbon|string $date): bool
    {
        return Carbon::parse($date)->startOfDay()->greaterThanOrEqualTo(now()->startOfDay()->subDay());
    }

    /**
     * Length of the run of low days on strictly consecutive calendar days
     * ending today or yesterday. A day is "low" when its latest entry is at
     * or below the threshold; extra entries on the same day never add days.
     * A missed day breaks the run; a run that ended in the past counts as 0.
     */
    private function lowStreak(Patient $patient): int
    {
        $threshold = (int) config('sakina.mood_alert_threshold', 3);
        $recent = MoodLog::where('patient_id', $patient->user_id)
            ->whereDate('log_date', '<=', now()->toDateString())
            ->whereDate('log_date', '>=', now()->subDays(14)->toDateString())
            ->orderByDesc('log_date')
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn (MoodLog $l) => $l->log_date->toDateString())
            ->values();

        $streak = 0;
        $expected = null;

        foreach ($recent as $entry) {
            $date = Carbon::parse($entry->log_date)->startOfDay();

            if ($expected === null && ! $this->isCurrent($date)) {
                break;
            }

            if ($entry->score > $threshold || ($expected !== null && ! $date->equalTo($expected))) {
                break;
            }

            $streak++;
            $expected = $date->copy()->subDay();
        }

        return $streak;
    }

    private function trend($logs): ?string
    {
        if ($logs->count() < 4) {
            return null;
        }

        $half = intdiv($logs->count(), 2);
        $first = $logs->take($half)->avg('score');
        $last = $logs->slice($half)->avg('score');
        $delta = $last - $first;

        return match (true) {
            $delta >= 0.75 => 'improving',
            $delta <= -0.75 => 'declining',
            default => 'stable',
        };
    }

    private function avgColumn($logs, string $column): ?float
    {
        $values = $logs->pluck($column)->filter(fn ($v) => $v !== null);

        return $values->isEmpty() ? null : round($values->avg(), 2);
    }

    public function toArray(MoodLog $log): array
    {
        return [
            'id' => $log->id,
            'score' => $log->score,
            'anxiety' => $log->anxiety,
            'energy' => $log->energy,
            'sleep_hours' => $log->sleep_hours,
            'activity_level' => $log->activity_level,
            'notes' => $log->notes,
            'log_date' => $log->log_date instanceof Carbon ? $log->log_date->toDateString() : $log->log_date,
            'alert_sent' => $log->alert_sent,
            'logged_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
