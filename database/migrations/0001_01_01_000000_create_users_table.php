<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Server-assigned role only — never accepted from client input.
            $table->enum('role', [
                'patient',
                'therapist',
                'super_admin',
                'admin',
                'clinical_supervisor',
                'finance_partner',
                'support_agent',
                'content_manager',
            ])->default('patient');

            $table->string('whatsapp_number')->unique();

            $table->boolean('is_active')->default(false);
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login')->nullable();
            $table->integer('login_attempts')->default(0);

            // Grace-period deletion: set when the user requests account removal.
            $table->timestamp('deletion_scheduled_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index(['is_active', 'phone_verified_at']);
            $table->index('role');
            $table->index('deletion_scheduled_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
    }
};
