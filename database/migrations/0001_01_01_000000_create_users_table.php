<?php
// database/migrations/[timestamp]_create_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // ⭐ ID الأساسي (نستخدم uuid بدلاً من auto-increment)
            $table->uuid('id')->primary();

            // ⭐ المعلومات الأساسية
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // ⭐ الحقول الجديدة لمشروعك
            $table->enum('role', [
                'patient',
                'therapist',
                'super_admin',
                'admin',
                'clinical_supervisor',
                'finance_partner',
                'support_agent',
                'content_manager'
            ])->default('patient');

            // ⭐ رقم الواتساب (مطلوب للنظام)
            $table->string('whatsapp_number')->unique();

            // ⭐ حالة التفعيل والتحقق
            $table->boolean('is_active')->default(false);
            $table->timestamp('phone_verified_at')->nullable(); // ⭐⭐⭐ الأهم ⭐⭐⭐

            // ⭐ معلومات إضافية
            $table->timestamp('last_login')->nullable();
            $table->integer('login_attempts')->default(0);

            // ⭐ توقيتات النظام
            $table->rememberToken();
            $table->timestamps();

            // ⭐ فهارس لتحسين الأداء
            $table->index('email');
            $table->index('whatsapp_number');
            $table->index('role');
            $table->index('is_active');
            $table->index(['is_active', 'phone_verified_at']); // للاستعلامات السريعة
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
