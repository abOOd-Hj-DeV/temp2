<?php

namespace App\Services\Assessment;

use App\Enums\AssessmentType;
use App\Enums\RedFlagPriority;
use App\Enums\RedFlagType;
use App\Models\Assessment;
use App\Models\Patient;
use App\Models\User;
use App\Repositories\Contracts\AssessmentRepositoryInterface;
use App\Repositories\Contracts\PatientRepositoryInterface;
use App\Services\NotificationService;
use App\Services\RedFlagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PHQ-9 / GAD-7 submission, scoring, interpretation, and red-flag escalation.
 *
 * Answers arrive keyed q1..qN with integer values 0-3.
 * Clinical rules:
 * - PHQ-9 item 9 (self-harm) > 0 always raises a HIGH-priority SAFETY flag,
 *   regardless of the total score.
 * - Score >= 15 raises a LOW_MOOD flag on either instrument.
 */
class AssessmentService
{
    private const SEVERE_SCORE = 20;

    private const FLAG_SCORE = 15;

    private const PHQ9_SELF_HARM_ITEM = 'q9';

    public function __construct(
        private AssessmentRepositoryInterface $assessments,
        private PatientRepositoryInterface $patients,
        private NotificationService $notifications,
        private RedFlagService $redFlags,
    ) {}

    public function createAssessment(User $user, array $data): array
    {
        $patient = $this->patients->findByUserId($user->id);

        if (! $patient) {
            throw ValidationException::withMessages([
                'profile' => __('Please complete your profile before taking an assessment.'),
            ]);
        }

        $type = AssessmentType::from($data['type']);
        $answers = $this->validateAnswers($type, $data['answers']);
        $score = array_sum($answers);

        $assessment = DB::transaction(function () use ($patient, $type, $answers, $score) {
            $assessment = $this->assessments->create([
                'id' => (string) Str::uuid(),
                'patient_id' => $patient->user_id,
                'type' => $type->value,
                'score' => $score,
                'answers' => $answers,
                'completed_at' => now(),
            ]);

            $this->patients->updateAssessmentScore($patient->user_id, $score);
            $this->escalateIfNeeded($patient, $assessment, $answers);

            return $assessment;
        });

        $this->notifications->assessmentCompleted($patient, $assessment);

        return [
            'assessment' => $this->toArray($assessment),
            'interpretation' => $this->interpret($type, $score),
            'recommendations' => $this->recommendations($score),
            'red_flag_created' => $assessment->redFlags()->exists(),
        ];
    }

    public function getHistory(User $user, int $perPage = 10): array
    {
        $patient = $this->patients->findByUserId($user->id);

        if (! $patient) {
            throw ValidationException::withMessages([
                'profile' => __('Please complete your profile first.'),
            ]);
        }

        $paginator = $this->assessments->getPatientHistory($patient->user_id, $perPage);

        return [
            'assessments' => collect($paginator->items())
                ->map(fn (Assessment $a) => $this->toArray($a))
                ->all(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'statistics' => $this->statistics($patient->user_id),
        ];
    }

    private function validateAnswers(AssessmentType $type, mixed $answers): array
    {
        $expected = $type === AssessmentType::PHQ9 ? 9 : 7;
        $expectedKeys = array_map(fn (int $i) => "q{$i}", range(1, $expected));

        if (! is_array($answers)
            || count($answers) !== $expected
            || array_diff($expectedKeys, array_keys($answers)) !== []) {
            throw ValidationException::withMessages([
                'answers' => __('Answers must contain exactly :count items named :keys.', [
                    'count' => $expected,
                    'keys' => implode(', ', $expectedKeys),
                ]),
            ]);
        }

        foreach ($answers as $key => $value) {
            if (! is_int($value) || $value < 0 || $value > 3) {
                throw ValidationException::withMessages([
                    "answers.{$key}" => __('Each answer must be an integer between 0 and 3.'),
                ]);
            }
        }

        return $answers;
    }

    private function escalateIfNeeded(Patient $patient, Assessment $assessment, array $answers): void
    {
        $isSafetyConcern = $assessment->type === AssessmentType::PHQ9
            && ($answers[self::PHQ9_SELF_HARM_ITEM] ?? 0) > 0;

        if ($isSafetyConcern) {
            $this->redFlags->createFromAssessment(
                $assessment,
                RedFlagType::SAFETY,
                RedFlagPriority::HIGH,
                __('PHQ-9 item 9 (self-harm ideation) answered above zero.')
            );
            $this->patients->updateSafetyFlag($patient->user_id, true);
        }

        if ($assessment->score >= self::FLAG_SCORE) {
            $this->redFlags->createFromAssessment(
                $assessment,
                RedFlagType::LOW_MOOD,
                $assessment->score >= self::SEVERE_SCORE ? RedFlagPriority::HIGH : RedFlagPriority::MEDIUM,
                __(':type score of :score meets the risk threshold.', [
                    'type' => strtoupper($assessment->type->value),
                    'score' => $assessment->score,
                ])
            );
        }
    }

    private function interpret(AssessmentType $type, int $score): array
    {
        $bands = $type === AssessmentType::PHQ9
            ? [[20, 'severe'], [15, 'moderately_severe'], [10, 'moderate'], [5, 'mild']]
            : [[15, 'severe'], [10, 'moderate'], [5, 'mild']];

        foreach ($bands as [$min, $level]) {
            if ($score >= $min) {
                return ['level' => $level, 'score' => $score, 'max_score' => $type->maxScore()];
            }
        }

        return ['level' => 'minimal', 'score' => $score, 'max_score' => $type->maxScore()];
    }

    private function recommendations(int $score): array
    {
        return match (true) {
            $score >= self::SEVERE_SCORE => [[
                'priority' => 'high',
                'action' => 'book_urgent_session',
                'title' => 'Urgent session recommended',
            ]],
            $score >= self::FLAG_SCORE => [[
                'priority' => 'medium',
                'action' => 'book_regular_session',
                'title' => 'Weekly session recommended',
            ]],
            default => [[
                'priority' => 'info',
                'action' => 'log_mood_daily',
                'title' => 'Keep tracking your mood daily',
            ]],
        };
    }

    private function statistics(string $patientId): array
    {
        $latest = $this->assessments->getLatestByPatientId($patientId);

        return [
            'total_assessments' => $this->assessments->findByPatientId($patientId)->count(),
            'average_phq9' => round($this->assessments->getAverageScore($patientId, AssessmentType::PHQ9->value), 1),
            'average_gad7' => round($this->assessments->getAverageScore($patientId, AssessmentType::GAD7->value), 1),
            'latest_score' => $latest?->score,
            'latest_type' => $latest?->type->value,
            'trend' => $this->trend($patientId),
        ];
    }

    private function trend(string $patientId): string
    {
        $scores = $this->assessments->findByPatientId($patientId)
            ->take(3)
            ->pluck('score');

        if ($scores->count() < 2) {
            return 'insufficient_data';
        }

        return match (true) {
            $scores->first() < $scores->last() - 3 => 'improving',
            $scores->first() > $scores->last() + 3 => 'declining',
            default => 'stable',
        };
    }

    private function toArray(Assessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'type' => $assessment->type->value,
            'score' => $assessment->score,
            'completed_at' => $assessment->completed_at?->toISOString(),
            'interpretation' => $this->interpret($assessment->type, $assessment->score),
        ];
    }
}
