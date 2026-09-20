<?php

namespace App\Services\Mood;

use App\Enums\RedFlagPriority;
use App\Enums\RedFlagType;
use App\Exceptions\ConflictException;
use App\Models\MoodLog;
use App\Models\Patient;
use App\Services\RedFlagService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Daily mood check-ins (1–10). One entry per patient per day; re-logging
 * the same day overwrites it. A streak of low scores raises a LOW_MOOD red
 * flag once (alert_sent) so the clinical team is not spammed.
 */
class MoodService
{
    public function __construct(private RedFlagService $redFlags) {}

    public function log(Patient $patient, array $data): array
    {
        $date = $data['log_date'] ?? now()->toDateString();

        try {
            $log = DB::transaction(function () use ($patient, $data, $date) {
                $log = MoodLog::where('patient_id', $patient->user_id)
                    ->whereDate('log_date', $date)
                    ->lockForUpdate()
                    ->first();

                $payload = [
                    'score' => (int) $data['score'],
                    'notes' => $data['notes'] ?? null,
                ];

                if ($log) {
                    $log->update($payload);
                    $log->wasRecentlyCreated = false;
                } else {
                    $log = MoodLog::create($payload + [
                        'id' => (string) Str::uuid(),
                        'patient_id' => $patient->user_id,
                        'log_date' => $date,
                    ]);
                }

                return $log;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException('A mood entry for this day was just recorded. Please retry.');
        }

        $alert = $this->maybeRaiseAlert($patient, $log);

        return [
            'mood' => $this->toArray($log),
            'created' => $log->wasRecentlyCreated,
            'alert_raised' => $alert,
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
            ->get();

        $byDate = $logs->keyBy(fn (MoodLog $l) => $l->log_date->toDateString());
        $series = [];

        for ($d = $from->copy(); $d->lte(now()->startOfDay()); $d->addDay()) {
            $key = $d->toDateString();
            $series[] = ['date' => $key, 'score' => $byDate->get($key)?->score];
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

        if ($this->lowStreak($patient) < $streakNeeded) {
            return false;
        }

        $this->redFlags->createFromMood(
            $patient,
            RedFlagType::LOW_MOOD,
            RedFlagPriority::MEDIUM,
            sprintf('Mood score ≤ %d for %d consecutive check-ins (latest %d/10 on %s).',
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
     * Length of the run of low scores on strictly consecutive calendar days
     * ending today or yesterday. A missed day breaks the run; a run that
     * ended in the past counts as 0.
     */
    private function lowStreak(Patient $patient): int
    {
        $threshold = (int) config('sakina.mood_alert_threshold', 3);
        $recent = MoodLog::where('patient_id', $patient->user_id)
            ->whereDate('log_date', '<=', now()->toDateString())
            ->orderByDesc('log_date')
            ->limit(14)
            ->get();

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

    public function toArray(MoodLog $log): array
    {
        return [
            'id' => $log->id,
            'score' => $log->score,
            'notes' => $log->notes,
            'log_date' => $log->log_date instanceof Carbon ? $log->log_date->toDateString() : $log->log_date,
            'alert_sent' => $log->alert_sent,
        ];
    }
}
