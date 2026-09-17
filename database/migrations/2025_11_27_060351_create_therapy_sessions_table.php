<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('patient_id')
                ->references('user_id')->on('patients')
                ->cascadeOnDelete();

            $table->foreignUuid('therapist_id')
                ->references('user_id')->on('therapists')
                ->cascadeOnDelete();

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

            $table->index(['patient_id', 'session_date']);
            $table->index(['therapist_id', 'session_date']);
            $table->index('status');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('therapy_sessions');
    }
};
