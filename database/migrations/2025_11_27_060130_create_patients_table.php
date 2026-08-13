<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_patients_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->string('full_name');
            $table->integer('age')->check('age >= 18');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->string('language')->default('ar');
            $table->integer('assessment_score')->default(0);
            $table->boolean('safety_flag')->default(false);

            // Foreign keys
            $table->uuid('therapist_id')->nullable();
            $table->uuid('subscription_id')->nullable();

            $table->enum('compliance_level', ['high', 'medium', 'low'])->default('medium');

            $table->timestamps();

            // Indexes
            $table->index('therapist_id');
            $table->index('subscription_id');
            $table->index('safety_flag');
            $table->index('compliance_level');
        });
    }

    public function down()
    {
        Schema::dropIfExists('patients');
    }
};
