<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 *  - therapy_sessions.subscription_id  the package that covers the session (price 0)
 *  - subscriptions.therapist_id        therapist who earns the package price
 *  - red_flags.previous_flag_id        stale flag this one supersedes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('therapist_id')->nullable()->constrained('therapists', 'user_id')->nullOnDelete();
        });

        Schema::table('red_flags', function (Blueprint $table) {
            $table->foreignUuid('previous_flag_id')->nullable()->constrained('red_flags')->nullOnDelete();
        });

        $this->restorePartialIndexes();
    }

    /**
     * SQLite (tests) rebuilds a table to add a foreign key and re-creates its
     * unique indexes without their WHERE clause; put the partial ones back.
     */
    private function restorePartialIndexes(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $indexes = [
            'therapy_sessions_active_slot_unique' => "ON therapy_sessions (therapist_id, session_date, session_time) WHERE status <> 'cancelled'",
            'red_flags_open_per_patient_type_unique' => "ON red_flags (patient_id, type) WHERE status = 'open'",
            'subscriptions_pending_per_patient_unique' => "ON subscriptions (patient_id) WHERE verification_status = 'pending'",
        ];

        foreach ($indexes as $name => $definition) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
            DB::statement("CREATE UNIQUE INDEX {$name} {$definition}");
        }
    }

    public function down(): void
    {
        Schema::table('red_flags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_flag_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('therapist_id');
        });

        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
        });
    }
};
