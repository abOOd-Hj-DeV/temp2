<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('review_reminder_sent_at')->nullable()->after('reviewed_at');
            $table->index(['status', 'review_reminder_sent_at', 'created_at'], 'payments_stale_review_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_stale_review_idx');
            $table->dropColumn('review_reminder_sent_at');
        });
    }
};
