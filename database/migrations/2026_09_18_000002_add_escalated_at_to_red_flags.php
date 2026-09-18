<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('red_flags', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('priority');
            $table->index(['status', 'escalated_at', 'created_at'], 'red_flags_stale_idx');
        });
    }

    public function down(): void
    {
        Schema::table('red_flags', function (Blueprint $table) {
            $table->dropIndex('red_flags_stale_idx');
            $table->dropColumn('escalated_at');
        });
    }
};
