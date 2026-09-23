<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_attempts')->default(0);
            $table->unsignedSmallInteger('reminder_1h_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->dropColumn(['reminder_attempts', 'reminder_1h_attempts']);
        });
    }
};
