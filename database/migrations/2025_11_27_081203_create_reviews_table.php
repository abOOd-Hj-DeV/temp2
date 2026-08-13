<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_reviews_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->uuid('therapist_id');
            $table->foreign('therapist_id')->references('user_id')->on('therapists')->onDelete('cascade');

            $table->integer('rating'); // 1-5
            $table->text('comment')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('patient_id');
            $table->index('therapist_id');
            $table->index('rating');

            // لمنع تقييم مزدوج
            $table->unique(['patient_id', 'therapist_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('reviews');
    }
};
