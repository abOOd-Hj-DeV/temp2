<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_safety_plans_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('safety_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->json('contact_info'); // معلومات الاتصال في حالات الطوارئ
            $table->text('coping_strategies')->nullable(); // استراتيجيات التعامل
            $table->text('emergency_contacts')->nullable(); // جهات اتصال طارئة
            $table->text('warning_signs')->nullable(); // علامات التحذير

            $table->timestamps();

            // Indexes
            $table->index('patient_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('safety_plans');
    }
};
