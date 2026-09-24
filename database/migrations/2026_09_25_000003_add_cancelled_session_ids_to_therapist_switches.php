<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapist_switches', function (Blueprint $table) {
            $table->json('cancelled_session_ids')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('therapist_switches', function (Blueprint $table) {
            $table->dropColumn('cancelled_session_ids');
        });
    }
};
