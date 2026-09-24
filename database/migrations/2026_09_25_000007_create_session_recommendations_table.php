<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured "what next" a therapist hands the patient after a completed
 * session: an optional package, an optional program, and a short note. One
 * recommendation per session, revisable by the same therapist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id')->unique();
            $table->foreign('session_id')->references('id')->on('therapy_sessions')->cascadeOnDelete();
            $table->uuid('therapist_id');
            $table->foreign('therapist_id')->references('user_id')->on('therapists')->cascadeOnDelete();
            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->cascadeOnDelete();
            $table->uuid('package_id')->nullable();
            $table->foreign('package_id')->references('id')->on('packages')->nullOnDelete();
            $table->uuid('program_id')->nullable();
            $table->foreign('program_id')->references('id')->on('programs')->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE session_recommendations ADD CONSTRAINT session_recommendations_has_content_check CHECK (package_id IS NOT NULL OR program_id IS NOT NULL OR note IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('session_recommendations');
    }
};
