<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('full_name');
            $table->unsignedTinyInteger('age');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->string('language')->default('ar');

            // Clinical fields — written by clinical services only, never by the patient.
            $table->unsignedTinyInteger('assessment_score')->default(0);
            $table->boolean('safety_flag')->default(false);
            $table->enum('compliance_level', ['high', 'medium', 'low'])->default('medium');

            // FK targets are created by later migrations; assigned by admin flows.
            $table->uuid('therapist_id')->nullable();
            $table->uuid('subscription_id')->nullable();

            $table->timestamps();

            $table->index('therapist_id');
            $table->index('subscription_id');
            $table->index('safety_flag');
            $table->index('compliance_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
