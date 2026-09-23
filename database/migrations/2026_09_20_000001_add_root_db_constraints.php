<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level invariants that application code must not be trusted to
 * uphold on its own. Every statement is valid on both PostgreSQL (runtime)
 * and SQLite (test suite).
 *
 *  - one *requested* therapist switch per patient
 *  - one *open* red flag per (patient, type)
 *  - one assessment per (patient, instrument, calendar day)
 *  - case-insensitive unique e-mail
 *  - non-negative money, bounded clinical scores, non-empty payment proof
 *  - audit_logs is append-only (UPDATE/DELETE rejected by trigger)
 *
 * down() removes only what up() added; it never deletes rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoDuplicates();

        DB::table('users')->whereRaw('email <> lower(email)')->update(['email' => DB::raw('lower(email)')]);

        DB::statement('CREATE UNIQUE INDEX therapist_switches_requested_unique
            ON therapist_switches (patient_id) WHERE status = \'requested\'');

        DB::statement('CREATE UNIQUE INDEX red_flags_open_per_patient_type_unique
            ON red_flags (patient_id, type) WHERE status = \'open\'');

        DB::statement('CREATE UNIQUE INDEX assessments_patient_type_day_unique
            ON assessments (patient_id, type, date(completed_at))');

        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');

        foreach ($this->checks() as $name => [$table, $expr]) {
            $this->addCheck($table, $name, $expr);
        }

        $this->createAuditImmutabilityTriggers();
    }

    public function down(): void
    {
        $this->dropAuditImmutabilityTriggers();

        foreach ($this->checks() as $name => [$table]) {
            $this->dropCheck($table, $name);
        }

        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        DB::statement('DROP INDEX IF EXISTS assessments_patient_type_day_unique');
        DB::statement('DROP INDEX IF EXISTS red_flags_open_per_patient_type_unique');
        DB::statement('DROP INDEX IF EXISTS therapist_switches_requested_unique');
    }

    /** @return array<string, array{0:string,1:string}> */
    private function checks(): array
    {
        return [
            'payments_amount_non_negative' => ['payments', 'amount >= 0'],
            'payments_proof_not_blank' => ['payments', 'length(trim(proof_file_path)) > 0'],
            'therapy_sessions_price_non_negative' => ['therapy_sessions', 'price >= 0'],
            'subscriptions_price_non_negative' => ['subscriptions', 'price >= 0'],
            'wallet_withdrawals_amount_positive' => ['wallet_withdrawals', 'amount > 0'],
            'wallet_withdrawals_status_check' => ['wallet_withdrawals', "status IN ('pending','approved','rejected','paid')"],
            'mood_logs_score_range' => ['mood_logs', 'score BETWEEN 1 AND 10'],
            'assessments_score_range' => ['assessments', 'score BETWEEN 0 AND 27'],
            'therapists_clients_limit_non_negative' => ['therapists', 'clients_limit >= 0'],
            'therapists_clients_count_non_negative' => ['therapists', 'clients_count >= 0'],
            'reviews_rating_range' => ['reviews', 'rating BETWEEN 1 AND 5'],
        ];
    }

    private function addCheck(string $table, string $name, string $expr): void
    {
        if ($this->isSqlite()) {
            // SQLite cannot ALTER TABLE ... ADD CONSTRAINT; the invariant is
            // enforced there by the application layer and by PostgreSQL in
            // every non-test environment.
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expr})");
    }

    private function dropCheck(string $table, string $name): void
    {
        if ($this->isSqlite()) {
            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
    }

    private function createAuditImmutabilityTriggers(): void
    {
        if ($this->isSqlite()) {
            DB::unprepared('CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
                BEGIN SELECT RAISE(ABORT, \'audit_logs is append-only\'); END;');
            DB::unprepared('CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
                BEGIN SELECT RAISE(ABORT, \'audit_logs is append-only\'); END;');

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only (% rejected)', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_mutation()');
        DB::unprepared('CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_mutation()');
    }

    private function dropAuditImmutabilityTriggers(): void
    {
        $on = $this->isSqlite() ? '' : ' ON audit_logs';

        DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_no_update{$on}");
        DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_no_delete{$on}");

        if (! $this->isSqlite()) {
            DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_reject_mutation()');
        }
    }

    /**
     * Refuse to run while rows exist that the new indexes would reject, so an
     * operator resolves them deliberately instead of a half-applied migration.
     */
    private function assertNoDuplicates(): void
    {
        $problems = [];

        $switches = DB::table('therapist_switches')->select('patient_id')
            ->where('status', 'requested')->groupBy('patient_id')->havingRaw('COUNT(*) > 1')->count();
        if ($switches > 0) {
            $problems[] = "{$switches} patient(s) with more than one requested therapist switch";
        }

        $flags = DB::table('red_flags')->select('patient_id', 'type')
            ->where('status', 'open')->groupBy('patient_id', 'type')->havingRaw('COUNT(*) > 1')->count();
        if ($flags > 0) {
            $problems[] = "{$flags} (patient, type) pair(s) with more than one open red flag";
        }

        $assessments = DB::table('assessments')
            ->selectRaw('patient_id, type, date(completed_at) as day')
            ->groupBy('patient_id', 'type', DB::raw('date(completed_at)'))->havingRaw('COUNT(*) > 1')->count();
        if ($assessments > 0) {
            $problems[] = "{$assessments} (patient, instrument, day) group(s) with duplicate assessments";
        }

        $emails = DB::table('users')->selectRaw('lower(email) as e')
            ->groupBy(DB::raw('lower(email)'))->havingRaw('COUNT(*) > 1')->count();
        if ($emails > 0) {
            $problems[] = "{$emails} e-mail address(es) that collide case-insensitively";
        }

        if (! $this->isSqlite()) {
            foreach ($this->checks() as $name => [$table, $expr]) {
                $violations = DB::table($table)->whereRaw("NOT ({$expr})")->count();
                if ($violations > 0) {
                    $problems[] = "{$violations} row(s) in {$table} violating {$name} ({$expr})";
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Cannot add root constraints: '.implode('; ', $problems)
                .'. Resolve the offending rows (see `php artisan sakina:dedupe --dry-run` for duplicates) and migrate again.'
            );
        }
    }

    private function isSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }
};
