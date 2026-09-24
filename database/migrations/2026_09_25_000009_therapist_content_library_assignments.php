<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A therapist's extra material becomes a reusable library: an item is owned
 * by the therapist and shared with any number of his clients through
 * therapist_content_assignments. The legacy single patient_id is kept
 * nullable as the "first addressee" and back-filled into the pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapist_content_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('content_id');
            $table->foreign('content_id')->references('id')->on('therapist_contents')->cascadeOnDelete();
            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();

            $table->unique(['content_id', 'patient_id']);
            $table->index(['patient_id', 'assigned_at']);
        });

        Schema::table('therapist_contents', function (Blueprint $table) {
            $table->uuid('patient_id')->nullable()->change();
        });

        $now = now();
        DB::table('therapist_contents')->whereNotNull('patient_id')->orderBy('id')
            ->chunk(200, function ($rows) use ($now) {
                DB::table('therapist_content_assignments')->insert($rows->map(fn ($row) => [
                    'id' => (string) Str::uuid(),
                    'content_id' => $row->id,
                    'patient_id' => $row->patient_id,
                    'assigned_at' => $row->created_at ?? $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapist_content_assignments');
    }
};
