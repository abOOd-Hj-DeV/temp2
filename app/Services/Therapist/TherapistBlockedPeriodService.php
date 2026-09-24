<?php

namespace App\Services\Therapist;

use App\Enums\SessionStatus;
use App\Exceptions\ConflictException;
use App\Models\Therapist;
use App\Models\TherapistBlockedPeriod;
use App\Models\TherapySession;
use App\Services\AuditLogService;
use App\Support\SessionClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Vacations and closed days. A blocked period hides every slot of the
 * covered therapist-local days (see TherapistService::availableSlotInstants);
 * it cannot be created over already-booked sessions — the therapist has to
 * cancel or move those first so no patient is silently left without a slot.
 */
class TherapistBlockedPeriodService
{
    public const MAX_DAYS = 90;

    public function __construct(private AuditLogService $audit) {}

    /** @return Collection<int, TherapistBlockedPeriod> */
    public function list(Therapist $therapist, bool $includePast = false): Collection
    {
        $today = Carbon::now($this->timezoneOf($therapist))->toDateString();

        return TherapistBlockedPeriod::where('therapist_id', $therapist->user_id)
            ->when(! $includePast, fn ($q) => $q->whereDate('end_date', '>=', $today))
            ->orderBy('start_date')
            ->get();
    }

    /**
     * @param  array{start_date: string, end_date?: string|null, reason?: string|null}  $data
     */
    public function create(Therapist $therapist, array $data): TherapistBlockedPeriod
    {
        $tz = $this->timezoneOf($therapist);
        $today = Carbon::now($tz)->toDateString();
        $start = substr((string) $data['start_date'], 0, 10);
        $end = substr((string) ($data['end_date'] ?? $start), 0, 10);

        if ($start < $today) {
            throw ValidationException::withMessages(['start_date' => 'Blocked periods cannot start in the past.']);
        }

        if ($end < $start) {
            throw ValidationException::withMessages(['end_date' => 'End date must be on or after the start date.']);
        }

        if (Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['end_date' => 'A blocked period cannot exceed '.self::MAX_DAYS.' days.']);
        }

        $overlaps = TherapistBlockedPeriod::where('therapist_id', $therapist->user_id)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($overlaps) {
            throw new ConflictException('This period overlaps an existing blocked period.');
        }

        $booked = $this->bookedSessionsWithin($therapist, $start, $end, $tz);

        if ($booked > 0) {
            throw ValidationException::withMessages([
                'start_date' => "You have {$booked} booked session(s) in this period. Cancel or reschedule them first.",
            ]);
        }

        $period = TherapistBlockedPeriod::create([
            'therapist_id' => $therapist->user_id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => isset($data['reason']) ? trim((string) $data['reason']) : null,
        ]);

        $this->audit->record($therapist->user_id, AuditLogService::THERAPIST_BLOCKED_PERIOD_CREATED, $period->id, [
            'start_date' => $start, 'end_date' => $end,
        ]);

        return $period;
    }

    public function delete(Therapist $therapist, string $id): void
    {
        $period = TherapistBlockedPeriod::where('therapist_id', $therapist->user_id)->whereKey($id)->firstOrFail();
        $period->delete();

        $this->audit->record($therapist->user_id, AuditLogService::THERAPIST_BLOCKED_PERIOD_DELETED, $id, [
            'start_date' => $period->start_date->toDateString(), 'end_date' => $period->end_date->toDateString(),
        ]);
    }

    public function toArray(TherapistBlockedPeriod $period): array
    {
        return [
            'id' => $period->id,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'reason' => $period->reason,
            'created_at' => $period->created_at?->toISOString(),
        ];
    }

    private function timezoneOf(Therapist $therapist): string
    {
        return $therapist->user?->timezone() ?? SessionClock::UTC;
    }

    /** Pending/confirmed sessions whose UTC start falls inside the local-day range. */
    private function bookedSessionsWithin(Therapist $therapist, string $start, string $end, string $tz): int
    {
        [$from] = SessionClock::dayBounds($start, $tz);
        [, $to] = SessionClock::dayBounds($end, $tz);

        return TherapySession::where('therapist_id', $therapist->user_id)
            ->whereIn('status', [SessionStatus::PENDING->value, SessionStatus::CONFIRMED->value])
            ->whereDate('session_date', '>=', $from->copy()->utc()->toDateString())
            ->whereDate('session_date', '<=', $to->copy()->utc()->toDateString())
            ->get()
            ->filter(function (TherapySession $session) use ($from, $to) {
                $startsAt = SessionClock::fromStored($session->session_date, $session->session_time);

                return $startsAt->gte($from) && $startsAt->lt($to);
            })
            ->count();
    }
}
