<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('red_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('patient_id')
                ->references('user_id')->on('patients')
                ->cascadeOnDelete();

            $table->uuid('assessment_id')->nullable();
            $table->foreign('assessment_id')
                ->references('id')->on('assessments')
                ->nullOnDelete();

            $table->enum('type', ['low_mood', 'non_compliance', 'safety']);
            $table->text('description');

            // null = not yet assigned to a staff member
            $table->foreignUuid('assigned_to')->nullable()
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->enum('status', ['open', 'resolved'])->default('open');
            $table->text('action_taken')->nullable();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');

            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index('assigned_to');
            $table->index('status');
            $table->index('priority');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('red_flags');
    }
};
