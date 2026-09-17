<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_status_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('session_id')
                ->references('id')->on('therapy_sessions')
                ->cascadeOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');

            $table->foreignUuid('actor_id')
                ->nullable()
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_status_logs');
    }
};
