<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery ledger for outbound notifications, one row per (event, recipient,
 * channel). It records *that* something was sent and whether it arrived —
 * never the message body or the phone number, which may carry clinical data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('event', 80);
            $table->string('event_key', 191);
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['channel', 'status', 'created_at']);
            $table->index('event_key');
            $table->index(['event', 'created_at']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_channel_check CHECK (channel IN ('in_app','whatsapp'))");
            DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_status_check CHECK (status IN ('queued','sent','failed','skipped'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
