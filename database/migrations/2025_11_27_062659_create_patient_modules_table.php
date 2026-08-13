<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_patient_modules_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('patient_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->uuid('module_id');
            $table->foreign('module_id')->references('id')->on('modules')->onDelete('cascade');

            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');

            $table->timestamps();

            // Indexes
            $table->index('patient_id');
            $table->index('module_id');
            $table->index('status');
            $table->index('completed_at');

            // لمنع التكرار
            $table->unique(['patient_id', 'module_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('patient_modules');
    }
};
