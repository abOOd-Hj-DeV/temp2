<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient cancellations need the therapist's approval. The request fields
 * mirror the reschedule flow; `cancel_rejected` marks a cancellation the
 * therapist refused — the session still ends cancelled but counts as used
 * (the patient forfeits it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->uuid('cancel_requested_by')->nullable()->after('reschedule_requested_at');
            $table->timestamp('cancel_requested_at')->nullable()->after('cancel_requested_by');
            $table->boolean('cancel_rejected')->default(false)->after('cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->dropColumn(['cancel_requested_by', 'cancel_requested_at', 'cancel_rejected']);
        });
    }
};
