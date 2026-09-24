<?php

namespace App\Services\Patient;

use App\Enums\RedFlagPriority;
use App\Enums\RedFlagType;
use App\Enums\SessionStatus;
use App\Models\Module;
use App\Models\Patient;
use App\Models\PatientModule;
use App\Models\Program;
use App\Models\SafetyPlan;
use App\Models\TherapySession;
use App\Models\User;
use App\Repositories\Contracts\AssessmentRepositoryInterface;
use App\Repositories\Contracts\PatientRepositoryInterface;
use App\Services\Program\ModuleAccessService;
use App\Services\RedFlagService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-models for the patient home screens: dashboard, progress,
 * appointments, and program content.
 */
class PatientDashboardService
{
    public function __construct(
        private PatientRepositoryInterface $patients,
        private AssessmentRepositoryInterface $assessments,
        private RedFlagService $redFlags,
        private ModuleAccessService $moduleAccess,
    ) {}

    public function dashboard(User $user): array
    {
        $patient = $this->requireProfile($user);
        $latest = $this->assessments->getLatestByPatientId($patient->user_id);
        $upcoming = $this->upcomingSessions($patient, 1)->first();

        return [
            'patient' => [
                'full_name' => $patient->full_name,
                'compliance_level' => $patient->compliance_level,
            ],
            'latest_assessment' => $latest ? [
                'type' => $latest->type->value,
                'score' => $latest->score,
                'completed_at' => $latest->completed_at?->toISOString(),
            ] : null,
            'next_session' => $upcoming ? $this->sessionToArray($upcoming) : null,
            'quick_actions' => ['take_assessment', 'log_mood', 'view_programs'],
        ];
    }

    public function progress(User $user): array
    {
        $patient = $this->requireProfile($user);

        $history = $this->assessments->findByPatientId($patient->user_id);

        return [
            'assessment_count' => $history->count(),
            'recent_scores' => $history->take(10)->map(fn ($a) => [
                'type' => $a->type->value,
                'score' => $a->score,
                'completed_at' => $a->completed_at?->toDateString(),
            ])->values()->all(),
            'current_score' => $patient->assessment_score,
            'compliance_level' => $patient->compliance_level,
        ];
    }

    public function appointments(User $user): array
    {
        $patient = $this->requireProfile($user);

        return [
            'upcoming' => $this->upcomingSessions($patient)
                ->map(fn (TherapySession $s) => $this->sessionToArray($s))
                ->all(),
            'past' => TherapySession::where('patient_id', $patient->user_id)
                ->whereIn('status', ['completed', 'cancelled'])
                ->orderByDesc('session_date')
                ->limit(10)
                ->get()
                ->map(fn (TherapySession $s) => $this->sessionToArray($s))
                ->all(),
        ];
    }

    /**
     * What the patient sees right after a session: the therapist's summary,
     * whether the session actually happened, and the next bookable step.
     */
    public function postSession(User $user, string $sessionId): array
    {
        $patient = $this->requireProfile($user);

        $session = TherapySession::with('therapist')
            ->where('patient_id', $patient->user_id)
            ->whereKey($sessionId)
            ->first();

        if (! $session) {
            throw new NotFoundHttpException('Session not found.');
        }

        $status = $session->status instanceof SessionStatus ? $session->status : SessionStatus::from($session->status);
        $canReview = $status === SessionStatus::COMPLETED;

        $next = $this->upcomingSessions($patient, 1)->first();

        return [
            'session' => $this->sessionToArray($session) + [
                'therapist_name' => $session->therapist?->full_name,
                'summary' => $canReview ? $session->summary : null,
            ],
            'completed' => $canReview,
            'next_session' => $next ? $this->sessionToArray($next) : null,
            'next_steps' => array_values(array_filter([
                $canReview ? null : 'wait_for_completion',
                $next ? null : 'book_next_session',
                'log_mood',
            ])),
        ];
    }

    public function programs(User $user): array
    {
        $patient = $this->requireProfile($user);
        $subscribed = $this->moduleAccess->hasActiveSubscription($patient);

        return [
            'has_active_subscription' => $subscribed,
            'programs' => Program::orderBy('is_core', 'desc')
                ->get()
                ->map(function (Program $p) use ($patient) {
                    $state = $this->moduleAccess->programState($patient, $p->id)
                        ->reject(fn (array $row) => $row['lock_reason'] === ModuleAccessService::REASON_HIDDEN);

                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'description' => $p->description,
                        'is_core' => (bool) $p->is_core,
                        'modules_count' => $state->count(),
                        'completed_count' => $state->filter(fn (array $row) => $row['progress']?->status === 'completed')->count(),
                        'modules' => $state->map(fn (array $row) => $this->moduleSummary($row))->values()->all(),
                    ];
                })
                ->all(),
        ];
    }

    public function moduleDetail(User $user, string $moduleId): array
    {
        $patient = $this->requireProfile($user);
        $module = $this->findModule($moduleId);
        $state = $this->requireUnlocked($patient, $module);

        return [
            'module' => $this->moduleToArray($state),
        ];
    }

    /**
     * Marks a module done, optionally recording the homework answers in the
     * same request. Locked modules cannot be completed: the first module is
     * free, the rest need an active package and the previous module finished.
     */
    public function completeModule(User $user, string $moduleId, ?array $homework = null): array
    {
        $patient = $this->requireProfile($user);
        $module = $this->findModule($moduleId);
        $this->requireUnlocked($patient, $module);

        $progress = PatientModule::firstOrCreate(
            ['patient_id' => $patient->user_id, 'module_id' => $module->id],
            ['id' => (string) Str::uuid(), 'status' => 'pending'],
        );

        if ($homework !== null) {
            $progress->fill(['homework' => $homework, 'homework_submitted_at' => now()])->save();
        }

        $progress->markAsCompleted();

        return [
            'module' => $this->moduleToArray($this->moduleAccess->stateFor($patient, $module)),
        ];
    }

    /** Saves (or replaces) the patient's answers to the module's homework without completing it. */
    public function submitHomework(User $user, string $moduleId, array $answers): array
    {
        $patient = $this->requireProfile($user);
        $module = $this->findModule($moduleId);
        $this->requireUnlocked($patient, $module);

        $progress = PatientModule::firstOrCreate(
            ['patient_id' => $patient->user_id, 'module_id' => $module->id],
            ['id' => (string) Str::uuid(), 'status' => 'pending'],
        );
        $progress->fill(['homework' => $answers, 'homework_submitted_at' => now()])->save();

        return [
            'module' => $this->moduleToArray($this->moduleAccess->stateFor($patient, $module)),
        ];
    }

    private function findModule(string $moduleId): Module
    {
        $module = Module::with('program:id,name,is_core')->find($moduleId);
        if (! $module) {
            throw new NotFoundHttpException('Module not found.');
        }

        return $module;
    }

    private function requireUnlocked(Patient $patient, Module $module): array
    {
        $state = $this->moduleAccess->stateFor($patient, $module);

        if ($state['lock_reason'] === ModuleAccessService::REASON_HIDDEN) {
            throw new NotFoundHttpException('Module not found.');
        }

        if ($state['locked']) {
            throw new AccessDeniedHttpException(match ($state['lock_reason']) {
                ModuleAccessService::REASON_SUBSCRIPTION => __('An active package is required to open this module.'),
                default => __('Complete the previous module first.'),
            });
        }

        return $state;
    }

    private function moduleSummary(array $state): array
    {
        /** @var Module $module */
        $module = $state['module'];
        /** @var ?PatientModule $progress */
        $progress = $state['progress'];

        return [
            'id' => $module->id,
            'title' => $module->title,
            'content_type' => $module->content_type,
            'order' => $module->order,
            'is_free' => $state['is_free'],
            'locked' => $state['locked'],
            'lock_reason' => $state['lock_reason'],
            'has_homework' => $module->homework_prompt !== null,
            'status' => $progress?->status ?? 'pending',
            'completed_at' => $progress?->completed_at?->toIso8601String(),
        ];
    }

    private function moduleToArray(array $state): array
    {
        /** @var Module $module */
        $module = $state['module'];
        /** @var ?PatientModule $progress */
        $progress = $state['progress'];

        return $this->moduleSummary($state) + [
            'program_id' => $module->program_id,
            'program_name' => $module->program?->name,
            'description' => $module->description,
            'body' => $module->body,
            'media_url' => $module->media_url,
            'exercise' => $module->exercise,
            'homework_prompt' => $module->homework_prompt,
            'tracking_tools' => $module->tracking_tools,
            'homework' => $progress?->homework,
            'homework_submitted_at' => $progress?->homework_submitted_at?->toIso8601String(),
        ];
    }

    public function emergency(User $user): array
    {
        $patient = $this->requireProfile($user);

        $plan = SafetyPlan::where('patient_id', $patient->user_id)->first();

        return [
            'hotline' => config('sakina.emergency.hotline'),
            'whatsapp' => config('sakina.emergency.whatsapp'),
            'local_services_note' => __('In an immediate emergency, please contact local emergency services.'),
            'safety_plan' => $plan ? [
                'contact_info' => $plan->contact_info,
                'emergency_contacts' => $plan->emergency_contacts,
                'coping_strategies' => $plan->coping_strategies,
                'warning_signs' => $plan->warning_signs,
            ] : null,
        ];
    }

    /**
     * The emergency screen's "talk to a clinical supervisor now" action:
     * raises (or bumps) a HIGH safety flag so staff are paged immediately.
     */
    public function emergencyAlert(User $user): array
    {
        $patient = $this->requireProfile($user);

        $flag = $this->redFlags->createFromMood(
            $patient,
            RedFlagType::SAFETY,
            RedFlagPriority::HIGH,
            'Patient requested immediate contact with a clinical supervisor from the emergency screen.',
        );

        return [
            'red_flag' => [
                'id' => $flag->id,
                'priority' => $flag->priority?->value ?? $flag->priority,
                'status' => $flag->status,
            ],
        ];
    }

    private function requireProfile(User $user): Patient
    {
        $patient = $this->patients->findByUserId($user->id);

        if (! $patient) {
            throw ValidationException::withMessages([
                'profile' => __('Please complete your profile first.'),
            ]);
        }

        return $patient;
    }

    private function upcomingSessions(Patient $patient, ?int $limit = null)
    {
        return TherapySession::where('patient_id', $patient->user_id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('session_date', '>=', now()->toDateString())
            ->orderBy('session_date')
            ->orderBy('session_time')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();
    }

    private function sessionToArray(TherapySession $session): array
    {
        return [
            'id' => $session->id,
            'therapist_id' => $session->therapist_id,
            'session_date' => $session->session_date?->toDateString(),
            'session_time' => $session->session_time,
            'medium' => $session->medium,
            'status' => $session->status,
            'is_initial' => (bool) $session->is_initial,
            'payment_status' => $session->payment_status,
            'price' => $session->price,
        ];
    }
}
