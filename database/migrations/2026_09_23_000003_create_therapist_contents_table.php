<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra therapeutic material a therapist writes for one of his clients, on
 * top of the fixed programme library (modules are package-owned). A
 * therapist's "library" is the set of all rows he created; patients only
 * ever see rows addressed to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapist_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('therapist_id');
            $table->foreign('therapist_id')->references('user_id')->on('therapists')->onDelete('cascade');

            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->string('title', 200);
            $table->enum('content_type', ['text', 'video', 'link']);
            $table->text('body')->nullable();   // required when type=text
            $table->string('url', 2000)->nullable(); // required when type=video|link

            $table->timestamps();

            $table->index(['therapist_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapist_contents');
    }
};
