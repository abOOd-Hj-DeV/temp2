<?php

namespace App\Services\Patient;

use App\Casts\ClinicalEncrypted;
use App\Models;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
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

    public const SESSION_REPORT_FIELDS = [
        Models\TherapySession::class => ['summary'],
        Models\SessionReportRevision::class => ['previous_summary', 'new_summary'],
    ];

    public function backfill(bool $rotate = false): void
    {
        $this->requireReportDriver();
        DB::transaction(function () use ($rotate) {
            foreach (self::FIELDS as $model => $columns) {
                $this->backfillModel($model, $columns, $rotate);
            }
            $this->backfillSessionReports($rotate);
        });
    }

    public function backfillSessionReports(bool $rotate = false): void
    {
        $this->requireReportDriver();
        DB::transaction(function () use ($rotate) {
            foreach (self::SESSION_REPORT_FIELDS as $model => $columns) {
                $this->backfillModel($model, $columns, $rotate);
            }
        });
    }

    private function backfillModel(string $model, array $columns, bool $rotate): void
    {
        $operation = function () use ($model, $columns, $rotate) {
            $model::query()->select('id')->eachById(function ($row) use ($model, $columns, $rotate) {
                DB::transaction(function () use ($row, $model, $columns, $rotate) {
                    $fresh = $model::whereKey($row->id)->lockForUpdate()->firstOrFail();
                    foreach ($columns as $column) {
                        $raw = $fresh->getRawOriginal($column);
                        if ($raw === null) {
                            continue;
                        }
                        if (str_starts_with($raw, ClinicalEncrypted::PREFIX)) {
                            try {
                                Crypt::decryptString(substr($raw, strlen(ClinicalEncrypted::PREFIX)));
                                if (! $rotate) {
                                    continue;
                                }
                            } catch (DecryptException $e) {
                                // Only the legacy migration interprets unauthenticated prefix collisions as plaintext.
                                $payload = json_decode((string) base64_decode(substr($raw, strlen(ClinicalEncrypted::PREFIX)), true), true);
                                if ($rotate || (is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']))) {
                                    throw $e;
                                }
                                $value = str_contains($fresh->getCasts()[$column], ':array')
                                    ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : $raw;
                                $fresh->setAttribute($column, $value);

                                continue;
                            }
                        }
                        $fresh->setAttribute($column, $fresh->getAttribute($column));
                    }
                    $fresh->timestamps = false;
                    $fresh->save();
                });
            }, 200);
        };

        if ($model !== Models\SessionReportRevision::class) {
            $operation();

            return;
        }

        $this->requireReportDriver();
        DB::transaction(function () use ($operation) {
            $postgres = DB::getDriverName() === 'pgsql';
            if ($postgres) {
                DB::statement('LOCK TABLE booking_report_revisions IN ACCESS EXCLUSIVE MODE');
            }
            // The exclusive transaction permits only lossless storage conversion, never runtime edits.
            DB::statement('DROP TRIGGER booking_report_revisions_no_update'.($postgres ? ' ON booking_report_revisions' : ''));
            $operation();
            DB::unprepared($postgres
                ? 'CREATE TRIGGER booking_report_revisions_no_update BEFORE UPDATE ON booking_report_revisions FOR EACH ROW EXECUTE FUNCTION booking_report_revisions_reject_update()'
                : "CREATE TRIGGER booking_report_revisions_no_update BEFORE UPDATE ON booking_report_revisions BEGIN SELECT RAISE(ABORT, 'Report revisions cannot be overwritten'); END;");
        });
    }

    private function requireReportDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new \RuntimeException('Clinical report backfill requires SQLite or PostgreSQL; no data or triggers were changed.');
        }
    }
}
