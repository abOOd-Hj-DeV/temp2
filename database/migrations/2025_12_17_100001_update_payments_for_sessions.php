<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A payment may pay for a subscription OR a single session.
        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('subscription_id')->nullable()->change();

            $table->foreignUuid('therapy_session_id')
                ->nullable()
                ->after('subscription_id')
                ->references('id')->on('therapy_sessions')
                ->nullOnDelete();

            $table->index('therapy_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['therapy_session_id']);
            $table->dropIndex(['therapy_session_id']);
            $table->dropColumn('therapy_session_id');

            $table->uuid('subscription_id')->nullable(false)->change();
        });
    }
};
