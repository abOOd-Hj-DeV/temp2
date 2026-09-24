<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mood_logs', function (Blueprint $table) {
            $table->dropUnique('mood_logs_patient_day_unique');
            $table->index(['patient_id', 'log_date', 'created_at'], 'mood_logs_patient_day_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('mood_logs', function (Blueprint $table) {
            $table->dropIndex('mood_logs_patient_day_created_idx');
            $table->unique(['patient_id', 'log_date'], 'mood_logs_patient_day_unique');
        });
    }
};
