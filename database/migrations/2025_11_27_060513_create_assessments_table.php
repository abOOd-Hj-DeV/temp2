<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_assessments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->enum('type', ['phq9', 'gad7']);
            $table->integer('score');
            $table->json('answers');
            $table->timestamp('completed_at');

            $table->timestamps();

            // Indexes
            $table->index('patient_id');
            $table->index('type');
            $table->index('completed_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('assessments');
    }
};
