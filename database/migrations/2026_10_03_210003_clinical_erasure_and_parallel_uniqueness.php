<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE parallel_layers IN SHARE ROW EXCLUSIVE MODE');
            }
            if (DB::table('parallel_layers')->select('patient_id', 'therapist_id')
                ->groupBy('patient_id', 'therapist_id')->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Duplicate clinical parallel layers require approved non-destructive reconciliation before migration. No content or schema was changed.');
            }
            Schema::create('clinical_erasure_plans', function (Blueprint $table) {
                $table->uuid('user_id')->primary();
                $table->json('paths');
                $table->json('directories');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
            Schema::table('parallel_layers', function (Blueprint $table) {
                $table->unique(['patient_id', 'therapist_id'], 'clinical_parallel_patient_therapist_unique');
            });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_erasure_plans');
        Schema::table('parallel_layers', fn (Blueprint $table) => $table->dropUnique('clinical_parallel_patient_therapist_unique'));
    }
};
