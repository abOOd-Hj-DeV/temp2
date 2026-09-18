<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-level guards against the race conditions that application-level
 * checks cannot prevent: double-booked slots, duplicate pending proofs and
 * duplicate live subscriptions. Partial unique indexes are supported by both
 * PostgreSQL and SQLite (used by the test suite).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoDuplicates();

        DB::statement(
            'CREATE UNIQUE INDEX therapy_sessions_active_slot_unique
             ON therapy_sessions (therapist_id, session_date, session_time)
             WHERE status <> \'cancelled\''
        );

        DB::statement(
            'CREATE UNIQUE INDEX payments_pending_session_unique
             ON payments (therapy_session_id)
             WHERE status = \'pending\' AND therapy_session_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX payments_pending_subscription_unique
             ON payments (subscription_id)
             WHERE status = \'pending\' AND subscription_id IS NOT NULL'
        );

        // Only *pending* is enforced here: an approved subscription expires
        // and must not block renewal. "One active subscription" is enforced
        // in SubscriptionService under a patient row lock.
        DB::statement(
            'CREATE UNIQUE INDEX subscriptions_pending_per_patient_unique
             ON subscriptions (patient_id)
             WHERE verification_status = \'pending\''
        );

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('reviewer_id');
        });

        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->boolean('reminder_1h_sent')->default(false)->after('reminder_sent');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('deletion_scheduled_at');
        });
    }

    /**
     * Refuse to run while rows exist that would violate the new indexes, so
     * an operator resolves them deliberately (php artisan sakina:dedupe
     * --dry-run) instead of the migration silently failing half-way.
     */
    private function assertNoDuplicates(): void
    {
        $problems = [];

        $slots = DB::table('therapy_sessions')
            ->select('therapist_id', 'session_date', 'session_time')
            ->where('status', '<>', 'cancelled')
            ->groupBy('therapist_id', 'session_date', 'session_time')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        if ($slots > 0) {
            $problems[] = "{$slots} therapist slot(s) with more than one active session";
        }

        $sessionPayments = DB::table('payments')
            ->select('therapy_session_id')
            ->where('status', 'pending')->whereNotNull('therapy_session_id')
            ->groupBy('therapy_session_id')->havingRaw('COUNT(*) > 1')->count();
        if ($sessionPayments > 0) {
            $problems[] = "{$sessionPayments} session(s) with more than one pending payment";
        }

        $subPayments = DB::table('payments')
            ->select('subscription_id')
            ->where('status', 'pending')->whereNotNull('subscription_id')
            ->groupBy('subscription_id')->havingRaw('COUNT(*) > 1')->count();
        if ($subPayments > 0) {
            $problems[] = "{$subPayments} subscription(s) with more than one pending payment";
        }

        $subs = DB::table('subscriptions')
            ->select('patient_id')
            ->where('verification_status', 'pending')
            ->groupBy('patient_id')->havingRaw('COUNT(*) > 1')->count();
        if ($subs > 0) {
            $problems[] = "{$subs} patient(s) with more than one pending subscription";
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Cannot add concurrency constraints: '.implode('; ', $problems)
                .'. Run `php artisan sakina:dedupe --dry-run` to inspect and `php artisan sakina:dedupe` to resolve, then migrate again.'
            );
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('anonymized_at'));
        Schema::table('therapy_sessions', fn (Blueprint $table) => $table->dropColumn('reminder_1h_sent'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('reviewed_at'));

        DB::statement('DROP INDEX IF EXISTS subscriptions_pending_per_patient_unique');
        DB::statement('DROP INDEX IF EXISTS payments_pending_subscription_unique');
        DB::statement('DROP INDEX IF EXISTS payments_pending_session_unique');
        DB::statement('DROP INDEX IF EXISTS therapy_sessions_active_slot_unique');
    }
};
