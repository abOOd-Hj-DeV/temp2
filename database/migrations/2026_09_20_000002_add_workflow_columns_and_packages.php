<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Schema for the workflow gaps found in the 189-case review:
 *
 *  - packages            Head-Master-defined catalogue (sessions, duration, daily quota)
 *  - subscriptions       package snapshot + free-text type (package code)
 *  - therapy_sessions    reschedule request awaiting therapist approval,
 *                        patient attendance confirmation, report revision counter
 *  - therapist_switches  two-step decision (target therapist, then supervisor)
 *  - red_flags           escalation attempt counter (retryable escalation)
 *  - idempotency_keys    replay protection for mutating requests
 *
 * down() drops only what up() added. It does not touch existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2);
            $table->unsignedSmallInteger('number_of_sessions');
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedTinyInteger('daily_sessions_quota')->default(1);
            $table->boolean('is_published')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('type', 64)->change();
            $table->foreignUuid('package_id')->nullable()->after('type')->constrained('packages')->restrictOnDelete();
            $table->unsignedSmallInteger('sessions_total')->nullable()->after('package_id');
            $table->unsignedSmallInteger('duration_days')->nullable()->after('sessions_total');
            $table->unsignedTinyInteger('daily_sessions_quota')->nullable()->after('duration_days');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_type_check');
        }

        $this->seedLegacyPackages();

        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->timestamp('attendance_confirmed_at')->nullable()->after('summary');
            $table->unsignedSmallInteger('report_revision')->default(0)->after('attendance_confirmed_at');
            $table->date('reschedule_date')->nullable()->after('report_revision');
            $table->time('reschedule_time')->nullable()->after('reschedule_date');
            $table->foreignUuid('reschedule_requested_by')->nullable()->after('reschedule_time')->constrained('users')->nullOnDelete();
            $table->timestamp('reschedule_requested_at')->nullable()->after('reschedule_requested_by');
        });

        Schema::table('therapist_switches', function (Blueprint $table) {
            $table->string('therapist_decision', 16)->nullable()->after('status');
            $table->timestamp('therapist_decided_at')->nullable()->after('therapist_decision');
            $table->foreignUuid('decided_by')->nullable()->after('therapist_decided_at')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
        });

        Schema::table('red_flags', function (Blueprint $table) {
            $table->unsignedSmallInteger('escalation_attempts')->default(0)->after('escalated_at');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->string('route', 160);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('locked_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');

            $table->unique(['user_id', 'key']);
            $table->index('expires_at');
        });

        $this->restorePartialIndexes();
    }

    /**
     * SQLite rebuilds a table to add a foreign-key column and re-creates its
     * indexes from PRAGMA metadata, which drops the WHERE predicate of partial
     * unique indexes (turning "one active slot" into "one slot ever"). Re-issue
     * the exact definitions so both drivers end with identical constraints.
     */
    private function restorePartialIndexes(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $indexes = [
            'therapy_sessions_active_slot_unique' => "ON therapy_sessions (therapist_id, session_date, session_time) WHERE status <> 'cancelled'",
            'payments_pending_session_unique' => "ON payments (therapy_session_id) WHERE status = 'pending' AND therapy_session_id IS NOT NULL",
            'payments_pending_subscription_unique' => "ON payments (subscription_id) WHERE status = 'pending' AND subscription_id IS NOT NULL",
            'subscriptions_pending_per_patient_unique' => "ON subscriptions (patient_id) WHERE verification_status = 'pending'",
            'therapist_switches_requested_unique' => "ON therapist_switches (patient_id) WHERE status = 'requested'",
            'red_flags_open_per_patient_type_unique' => "ON red_flags (patient_id, type) WHERE status = 'open'",
        ];

        foreach ($indexes as $name => $definition) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
            DB::statement("CREATE UNIQUE INDEX {$name} {$definition}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');

        Schema::table('red_flags', fn (Blueprint $table) => $table->dropColumn('escalation_attempts'));

        Schema::table('therapist_switches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['therapist_decision', 'therapist_decided_at', 'decided_at']);
        });

        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reschedule_requested_by');
            $table->dropColumn(['attendance_confirmed_at', 'report_revision', 'reschedule_date', 'reschedule_time', 'reschedule_requested_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
            $table->dropColumn(['sessions_total', 'duration_days', 'daily_sessions_quota']);
        });
        // subscriptions.type stays a free string: re-adding the enum CHECK could
        // fail on rows that reference newer package codes.

        Schema::dropIfExists('packages');
    }

    /**
     * The two hard-coded plans become the first catalogue rows so existing
     * subscriptions (type = '4_weeks' | '8_weeks') keep resolving.
     */
    private function seedLegacyPackages(): void
    {
        $now = now();
        $rows = [
            ['code' => '4_weeks', 'name' => '4 Weeks', 'price' => config('sakina.subscription_prices.4_weeks', 150.00), 'number_of_sessions' => 4, 'duration_days' => 28],
            ['code' => '8_weeks', 'name' => '8 Weeks', 'price' => config('sakina.subscription_prices.8_weeks', 260.00), 'number_of_sessions' => 8, 'duration_days' => 56],
        ];

        foreach ($rows as $row) {
            $id = (string) Str::uuid();

            DB::table('packages')->insert($row + [
                'id' => $id,
                'daily_sessions_quota' => 1,
                'is_published' => true,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('subscriptions')->where('type', $row['code'])->whereNull('package_id')->update([
                'package_id' => $id,
                'sessions_total' => $row['number_of_sessions'],
                'duration_days' => $row['duration_days'],
                'daily_sessions_quota' => 1,
            ]);
        }
    }
};
