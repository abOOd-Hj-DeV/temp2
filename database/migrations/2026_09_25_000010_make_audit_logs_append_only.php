<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs becomes append-only at the database level: row triggers reject
 * every UPDATE/DELETE (and TRUNCATE on PostgreSQL) regardless of which
 * connection or code path issues it. The actor FK is dropped so that the
 * historical actor id survives account hard-deletes instead of cascading
 * into the log; the column stays indexed.
 */
return new class extends Migration
{
    private const MESSAGE = 'audit_logs is append-only';

    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        match (DB::getDriverName()) {
            'pgsql' => $this->upPgsql(),
            'sqlite' => $this->upSqlite(),
            'mysql', 'mariadb' => $this->upMysql(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->downPgsql(),
            'sqlite' => $this->downSqlite(),
            'mysql', 'mariadb' => $this->downMysql(),
            default => null,
        };

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    private function upPgsql(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only (% rejected)', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs;
            CREATE TRIGGER audit_logs_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();

            DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
            CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_append_only();
        SQL);
    }

    private function downPgsql(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs;
            DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
            DROP FUNCTION IF EXISTS audit_logs_append_only();
        SQL);
    }

    private function upSqlite(): void
    {
        DB::unprepared(sprintf(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS audit_logs_no_update BEFORE UPDATE ON audit_logs
            BEGIN SELECT RAISE(ABORT, '%1$s'); END;
            CREATE TRIGGER IF NOT EXISTS audit_logs_no_delete BEFORE DELETE ON audit_logs
            BEGIN SELECT RAISE(ABORT, '%1$s'); END;
        SQL, self::MESSAGE));
    }

    private function downSqlite(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update; DROP TRIGGER IF EXISTS audit_logs_no_delete;');
    }

    private function upMysql(): void
    {
        DB::unprepared(sprintf(<<<'SQL'
            CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%1$s';
        SQL, self::MESSAGE));
        DB::unprepared(sprintf(<<<'SQL'
            CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%1$s';
        SQL, self::MESSAGE));
    }

    private function downMysql(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
    }
};
