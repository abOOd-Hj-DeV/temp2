<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tracker logs four signals per day, not one: mood (`score`), anxiety,
 * energy, sleep and activity. The low-mood red-flag streak stays on `score`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mood_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('anxiety')->nullable()->after('score');   // 1-10
            $table->unsignedTinyInteger('energy')->nullable()->after('anxiety');  // 1-10
            $table->decimal('sleep_hours', 4, 1)->nullable()->after('energy');    // 0.0-24.0
            $table->unsignedTinyInteger('activity_level')->nullable()->after('sleep_hours'); // 1-5
        });
    }

    public function down(): void
    {
        Schema::table('mood_logs', function (Blueprint $table) {
            $table->dropColumn(['anxiety', 'energy', 'sleep_hours', 'activity_level']);
        });
    }
};
