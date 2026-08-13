<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_sessions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Foreign keys
            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->uuid('therapist_id');
            $table->foreign('therapist_id')->references('user_id')->on('therapists')->onDelete('cascade');

            $table->date('session_date');
            $table->time('session_time');
            $table->enum('medium', ['zoom', 'meet', 'whatsapp']);
            $table->decimal('price', 8, 2)->default(0);
            $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled'])->default('pending');
            $table->string('link')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('is_initial')->default(false);
            $table->enum('payment_status', ['paid', 'pending', 'free'])->default('pending');
            $table->boolean('reminder_sent')->default(false);

            $table->timestamps();

            // Indexes
            $table->index('patient_id');
            $table->index('therapist_id');
            $table->index('session_date');
            $table->index('status');
            $table->index('payment_status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sessions');
    }
};
