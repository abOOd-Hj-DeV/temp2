<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_red_flags_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('red_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->enum('type', ['low_mood', 'non_compliance', 'safety']);
            $table->text('description');

            $table->uuid('assigned_to'); // المستخدم المسؤول
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('cascade');

            $table->enum('status', ['open', 'resolved'])->default('open');
            $table->text('action_taken')->nullable();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');

            $table->timestamps();

            // Indexes
            $table->index('patient_id');
            $table->index('assigned_to');
            $table->index('status');
            $table->index('priority');
            $table->index('type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('red_flags');
    }
};
