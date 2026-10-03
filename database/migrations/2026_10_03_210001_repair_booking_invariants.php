<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS subscriptions_pending_per_patient_unique');
        DB::statement("CREATE UNIQUE INDEX subscriptions_pending_per_patient_unique ON subscriptions (patient_id) WHERE verification_status = 'pending' AND cancelled_at IS NULL");

        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->unsignedInteger('schedule_version')->default(1);
            $table->unsignedInteger('attendance_schedule_version')->nullable();
            $table->json('schedule_history')->nullable();
        });

        // Preserve legacy attendance; every subsequent move invalidates it.
        DB::table('therapy_sessions')->whereNotNull('attendance_confirmed_at')->update(['attendance_schedule_version' => 1]);

        Schema::create('booking_report_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('therapy_sessions')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->uuid('actor_id');
            $table->text('previous_summary')->nullable();
            $table->text('new_summary')->nullable();
            $table->string('previous_sha256', 64)->nullable();
            $table->string('new_sha256', 64);
            $table->timestamp('created_at');
            $table->unique(['session_id', 'revision']);
        });

        // Clinical bodies follow session erasure; existing revisions cannot be overwritten.
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER booking_report_revisions_no_update BEFORE UPDATE ON booking_report_revisions BEGIN SELECT RAISE(ABORT, 'Report revisions cannot be overwritten'); END;");
            DB::unprepared("CREATE TRIGGER booking_report_revisions_no_delete BEFORE DELETE ON booking_report_revisions WHEN EXISTS (SELECT 1 FROM therapy_sessions s JOIN users u ON u.id = s.patient_id WHERE s.id = OLD.session_id AND u.anonymized_at IS NULL) BEGIN SELECT RAISE(ABORT, 'Report revisions follow patient/session erasure'); END;");
            DB::unprepared('CREATE TRIGGER booking_report_revisions_patient_erasure AFTER UPDATE ON patients WHEN EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND anonymized_at IS NOT NULL) BEGIN DELETE FROM booking_report_revisions WHERE session_id IN (SELECT id FROM therapy_sessions WHERE patient_id = NEW.user_id); END;');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION booking_report_revisions_reject_update() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'UPDATE' OR EXISTS (SELECT 1 FROM therapy_sessions s JOIN users u ON u.id = s.patient_id WHERE s.id = OLD.session_id AND u.anonymized_at IS NULL) THEN
                        RAISE EXCEPTION 'Report revisions cannot be overwritten or deleted independently' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER booking_report_revisions_no_update BEFORE UPDATE ON booking_report_revisions
                    FOR EACH ROW EXECUTE FUNCTION booking_report_revisions_reject_update();
                CREATE TRIGGER booking_report_revisions_no_delete BEFORE DELETE ON booking_report_revisions
                    FOR EACH ROW EXECUTE FUNCTION booking_report_revisions_reject_update();
                CREATE OR REPLACE FUNCTION booking_report_revisions_patient_erasure() RETURNS trigger AS $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND anonymized_at IS NOT NULL) THEN
                        DELETE FROM booking_report_revisions WHERE session_id IN (SELECT id FROM therapy_sessions WHERE patient_id = NEW.user_id);
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER booking_report_revisions_patient_erasure AFTER UPDATE ON patients
                    FOR EACH ROW EXECUTE FUNCTION booking_report_revisions_patient_erasure();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS booking_report_revisions_patient_erasure');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS booking_report_revisions_patient_erasure ON patients');
            DB::unprepared('DROP FUNCTION IF EXISTS booking_report_revisions_patient_erasure()');
        }
        Schema::dropIfExists('booking_report_revisions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS booking_report_revisions_reject_update()');
        }
        Schema::table('therapy_sessions', fn (Blueprint $table) => $table->dropColumn(['schedule_version', 'attendance_schedule_version', 'schedule_history']));
        DB::statement('DROP INDEX IF EXISTS subscriptions_pending_per_patient_unique');
        DB::statement("CREATE UNIQUE INDEX subscriptions_pending_per_patient_unique ON subscriptions (patient_id) WHERE verification_status = 'pending'");
    }
};
