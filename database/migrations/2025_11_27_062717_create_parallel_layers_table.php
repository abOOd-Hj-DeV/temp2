<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_parallel_layers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('parallel_layers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->uuid('therapist_id');
            $table->foreign('therapist_id')->references('user_id')->on('therapists')->onDelete('cascade');

            $table->json('content');
            $table->json('edit_log')->nullable(); // سجل التعديلات

            $table->timestamps();

            // Indexes
            $table->index('patient_id');
            $table->index('therapist_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('parallel_layers');
    }
};
