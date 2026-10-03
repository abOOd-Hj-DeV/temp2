<?php

namespace App\Services\Patient;

use App\Casts\ClinicalEncrypted;
use App\Models;
use Illuminate\Support\Facades\DB;

class ClinicalEncryption
{
    public const FIELDS = [
        Models\MoodLog::class => ['notes'],
        Models\Support::class => ['subject', 'description'],
        Models\SupportReply::class => ['body'],
        Models\PatientModule::class => ['homework'],
        Models\ParallelLayer::class => ['content', 'edit_log'],
        Models\SafetyPlan::class => ['contact_info', 'coping_strategies', 'emergency_contacts', 'warning_signs'],
        Models\TherapistClientNote::class => ['body'],
        Models\RedFlag::class => ['description', 'action_taken'],
        Models\TherapistSwitch::class => ['reason'],
        Models\SessionRecommendation::class => ['note'],
        Models\Review::class => ['comment'],
    ];

    public function backfill(bool $rotate = false): void
    {
        foreach (self::FIELDS as $model => $columns) {
            $model::query()->select('id')->eachById(function ($row) use ($model, $columns, $rotate) {
                DB::transaction(function () use ($row, $model, $columns, $rotate) {
                    $fresh = $model::whereKey($row->id)->lockForUpdate()->firstOrFail();
                    foreach ($columns as $column) {
                        $raw = $fresh->getRawOriginal($column);
                        if ($raw !== null && ($rotate || ! str_starts_with($raw, ClinicalEncrypted::PREFIX))) {
                            $fresh->setAttribute($column, $fresh->getAttribute($column));
                        }
                    }
                    $fresh->timestamps = false;
                    $fresh->save();
                });
            }, 200);
        }
    }
}
