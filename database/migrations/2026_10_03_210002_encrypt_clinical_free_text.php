<?php

use App\Services\Patient\ClinicalEncryption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (ClinicalEncryption::FIELDS as $model => $columns) {
            $tableName = (new $model)->getTable();
            Schema::table($tableName, function (Blueprint $table) use ($columns, $tableName) {
                foreach ($columns as $column) {
                    $required = in_array("{$tableName}.{$column}", ['supports.description', 'support_replies.body', 'parallel_layers.content', 'safety_plans.contact_info', 'therapist_client_notes.body', 'red_flags.description', 'therapist_switches.reason']);
                    $table->text($column)->nullable(! $required)->change();
                }
            });
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS red_flags_open_per_patient_type_unique');
            DB::statement("CREATE UNIQUE INDEX red_flags_open_per_patient_type_unique ON red_flags (patient_id, type) WHERE status = 'open'");
            DB::statement('DROP INDEX IF EXISTS therapist_switches_requested_unique');
            DB::statement("CREATE UNIQUE INDEX therapist_switches_requested_unique ON therapist_switches (patient_id) WHERE status = 'requested'");
        }
        app(ClinicalEncryption::class)->backfill();
    }

    public function down(): void
    {
        // Ciphertext must never be implicitly converted back to plaintext.
    }
};
