<?php

namespace App\Services\Program;

use App\Models\Module;
use App\Models\Patient;
use App\Models\PatientModule;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Decides which programme modules a patient may open.
 *
 * Commercial rule: the first module of every programme is free; every later
 * module needs an active (approved, unexpired, uncancelled) subscription AND
 * the previous module completed, so content unlocks one step at a time.
 * A therapist may hide a module marked hideable for one patient; hidden
 * modules drop out of the sequence entirely.
 */
class ModuleAccessService
{
    public const REASON_HIDDEN = 'hidden_by_therapist';

    public const REASON_SUBSCRIPTION = 'subscription_required';

    public const REASON_SEQUENCE = 'previous_module_incomplete';

    public function __construct(private SubscriptionRepositoryInterface $subscriptions) {}

    /**
     * Ordered modules of a programme annotated with the patient's progress and
     * lock state. Each element: ['module' => Module, 'progress' => ?PatientModule,
     * 'locked' => bool, 'lock_reason' => ?string, 'is_free' => bool].
     *
     * @return BaseCollection<int, array{module: Module, progress: ?PatientModule, locked: bool, lock_reason: ?string, is_free: bool}>
     */
    public function programState(Patient $patient, string $programId): BaseCollection
    {
        $modules = Module::where('program_id', $programId)
            ->orderBy('order')->orderBy('created_at')->orderBy('id')
            ->get();

        return $this->annotate($patient, $modules);
    }

    /** Lock state of one module within its programme. */
    public function stateFor(Patient $patient, Module $module): array
    {
        $state = $this->programState($patient, $module->program_id)
            ->first(fn (array $row) => $row['module']->id === $module->id);

        return $state ?? [
            'module' => $module,
            'progress' => null,
            'locked' => true,
            'lock_reason' => self::REASON_SEQUENCE,
            'is_free' => false,
        ];
    }

    public function hasActiveSubscription(Patient $patient): bool
    {
        return $this->subscriptions->activeForPatient($patient->user_id) !== null;
    }

    /**
     * @param  Collection<int, Module>  $modules
     * @return BaseCollection<int, array{module: Module, progress: ?PatientModule, locked: bool, lock_reason: ?string, is_free: bool}>
     */
    private function annotate(Patient $patient, Collection $modules): BaseCollection
    {
        $progress = PatientModule::where('patient_id', $patient->user_id)
            ->whereIn('module_id', $modules->pluck('id'))
            ->get()
            ->keyBy('module_id');

        $subscribed = null;
        $previousCompleted = true;
        $position = 0;
        $rows = [];

        foreach ($modules as $module) {
            /** @var ?PatientModule $row */
            $row = $progress->get($module->id);

            if ($row !== null && $row->hidden_at !== null) {
                $rows[] = [
                    'module' => $module, 'progress' => $row,
                    'locked' => true, 'lock_reason' => self::REASON_HIDDEN, 'is_free' => false,
                ];

                continue;
            }

            $isFree = $position === 0;
            $reason = null;

            if (! $isFree) {
                $subscribed ??= $this->hasActiveSubscription($patient);
                if (! $subscribed) {
                    $reason = self::REASON_SUBSCRIPTION;
                } elseif (! $previousCompleted) {
                    $reason = self::REASON_SEQUENCE;
                }
            }

            $rows[] = [
                'module' => $module, 'progress' => $row,
                'locked' => $reason !== null, 'lock_reason' => $reason, 'is_free' => $isFree,
            ];

            $previousCompleted = $row !== null && $row->status === 'completed';
            $position++;
        }

        return collect($rows);
    }
}
