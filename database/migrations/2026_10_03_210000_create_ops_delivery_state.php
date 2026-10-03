<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_notification_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('dedupe_key', 64)->unique();
            $table->text('payload')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index('lease_until');
        });

        Schema::create('ops_session_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('therapy_sessions')->cascadeOnDelete();
            $table->string('schedule_key', 64);
            $table->string('window', 8);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'schedule_key', 'window'], 'ops_reminder_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_session_reminders');
        Schema::dropIfExists('ops_notification_outbox');
    }
};
