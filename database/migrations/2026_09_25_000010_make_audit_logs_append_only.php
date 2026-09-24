<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the append-only guarantee started in 2026_09_20_000001:
 *
 *  - audit_logs.user_id no longer references users. The cascade made a user
 *    hard-delete try to delete audit rows, which the row triggers reject, so
 *    the delete failed; now the historical actor id simply outlives the
 *    account. The column stays indexed.
 *  - PostgreSQL additionally rejects TRUNCATE (row triggers do not fire on it).
 *  - SQLite rebuilds the table when a foreign key is dropped/added, which
 *    silently discards its triggers, so they are re-created after each rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        match (DB::getDriverName()) {
            'pgsql' => $this->ensurePgsqlTriggers(),
            'sqlite' => $this->ensureSqliteTriggers(),
            default => null,
        };
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs');
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->ensureSqliteTriggers();
        }
    }

    private function ensurePgsqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only (% rejected)', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs;
            CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_mutation();

            DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs;
            CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_mutation();

            DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
            CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_reject_mutation();
        SQL);
    }

    private function ensureSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS audit_logs_no_update BEFORE UPDATE ON audit_logs
            BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;
            CREATE TRIGGER IF NOT EXISTS audit_logs_no_delete BEFORE DELETE ON audit_logs
            BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END;
        SQL);
    }
};
