<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_erasure_plans');
        Schema::table('parallel_layers', fn (Blueprint $table) => $table->dropUnique('clinical_parallel_patient_therapist_unique'));
    }
};
