<?php

namespace App\Services\Patient;

use App\Enums\ComplianceLevel;
use App\Enums\RedFlagPriority;
use App\Enums\RedFlagType;
use App\Models\MoodLog;
use App\Models\Patient;
use App\Models\PatientModule;
use App\Models\RedFlag;
use App\Models\Subscription;
use App\Services\RedFlagService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

/**
 * Weekly engagement snapshot: how many of the last 7 days the patient logged a
 * mood (60%) and what share of their assigned modules they completed (40%).
 * Patients without assigned modules are scored on mood alone. Dropping to LOW
 * raises a single open non-compliance red flag for the clinical team.
 */
class ComplianceService
{
    public const WINDOW_DAYS = 7;

    private const MOOD_WEIGHT = 0.6;

    private const MODULE_WEIGHT = 0.4;

    public function __construct(private RedFlagService $redFlags) {}

    /** @return array{score:int, level:ComplianceLevel, mood_days:int, modules_completed:int, modules_due:int} */
    public function snapshot(Patient $patient, CarbonInterface $asOf): array
    {
        $windowStart = $asOf->copy()->startOfDay()->subDays(self::WINDOW_DAYS);
        $windowEnd = $asOf->copy()->startOfDay();

        $moodDays = MoodLog::where('patient_id', $patient->user_id)
            ->whereDate('log_date', '>=', $windowStart->toDateString())
            ->whereDate('log_date', '<', $windowEnd->toDateString())
            ->distinct()
            ->count('log_date');

        $moodScore = min(100, $moodDays / self::WINDOW_DAYS * 100);

        $modules = PatientModule::where('patient_id', $patient->user_id)
            ->where('created_at', '<', $windowEnd)
            ->where(function ($q) use ($windowStart) {
                $q->where('status', 'pending')
                    ->orWhere('completed_at', '>=', $windowStart);
            })
            ->get();

        $modulesDue = $modules->count();
        $modulesCompleted = $modules->where('status', 'completed')->count();

        if ($modulesDue === 0) {
            $score = (int) round($moodScore);
        } else {
            $moduleScore = $modulesCompleted / $modulesDue * 100;
            $score = (int) round(self::MOOD_WEIGHT * $moodScore + self::MODULE_WEIGHT * $moduleScore);
        }

        return [
            'score' => $score,
            'level' => ComplianceLevel::fromScore($score),
            'mood_days' => $moodDays,
            'modules_completed' => $modulesCompleted,
            'modules_due' => $modulesDue,
        ];
    }

    /**
     * Persist the level and, on a drop to LOW, raise one non-compliance flag
     * (never a second one while the previous is still open).
     */
    public function apply(Patient $patient, CarbonInterface $asOf): ComplianceLevel
    {
        $snapshot = $this->snapshot($patient, $asOf);
        $level = $snapshot['level'];
        $previous = $patient->compliance_level instanceof ComplianceLevel
            ? $patient->compliance_level
            : ComplianceLevel::tryFrom((string) $patient->compliance_level);

        Patient::whereKey($patient->user_id)->update(['compliance_level' => $level->value]);

        if ($level === ComplianceLevel::LOW && ! $this->hasOpenNonComplianceFlag($patient)) {
            $this->redFlags->createFromMood(
                $patient,
                RedFlagType::NON_COMPLIANCE,
                RedFlagPriority::LOW,
                sprintf(
                    'Weekly compliance dropped to LOW (score %d/100: %d/%d mood check-ins, %d/%d modules completed).',
                    $snapshot['score'],
                    $snapshot['mood_days'],
                    self::WINDOW_DAYS,
                    $snapshot['modules_completed'],
                    $snapshot['modules_due'],
                ),
            );
        }

        Log::info('Weekly compliance computed', [
            'patient_id' => $patient->user_id,
            'score' => $snapshot['score'],
            'level' => $level->value,
            'previous' => $previous?->value,
        ]);

        return $level;
    }

    private function hasOpenNonComplianceFlag(Patient $patient): bool
    {
        return RedFlag::where('patient_id', $patient->user_id)
            ->where('type', RedFlagType::NON_COMPLIANCE->value)
            ->where('status', 'open')
            ->exists();
    }

    /**
     * Patients currently enrolled (active, approved subscription).
     */
    public function enrolledPatients(): LazyCollection
    {
        return Patient::query()
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->whereIn('user_id', Subscription::query()
                ->select('patient_id')
                ->where('verification_status', 'approved')
                ->whereNull('cancelled_at')
                ->whereNotNull('end_date')
                ->where('end_date', '>=', now()->toDateString()))
            ->lazyById(200, 'user_id');
    }
}
