<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapist_client_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('therapist_id')->constrained('therapists', 'user_id')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients', 'user_id')->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('therapy_sessions')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['therapist_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapist_client_notes');
    }
};
