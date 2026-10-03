<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_audit_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('family_id');
            $table->string('action');
            $table->uuid('entity_id');
            $table->unsignedBigInteger('credential_generation');
            $table->timestamp('occurred_at');
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at');
            $table->string('last_error', 32)->nullable();
            $table->index(['delivered_at', 'next_attempt_at']);
        });

        $columns = ['id', 'user_id', 'family_id', 'action', 'entity_id', 'credential_generation', 'occurred_at'];
        if (DB::getDriverName() === 'pgsql') {
            $new = implode(', ', array_map(fn ($column) => "NEW.$column", $columns));
            $old = implode(', ', array_map(fn ($column) => "OLD.$column", $columns));
            DB::unprepared("CREATE OR REPLACE FUNCTION security_audit_intents_protect() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF ROW($new) IS NOT DISTINCT FROM ROW($old) THEN RETURN NEW; END IF;
                END IF;
                RAISE EXCEPTION 'security audit intent payload is immutable' USING ERRCODE = 'restrict_violation';
            END; \$\$");
            DB::unprepared('CREATE TRIGGER security_audit_intents_no_mutation BEFORE UPDATE OR DELETE ON security_audit_intents FOR EACH ROW EXECUTE FUNCTION security_audit_intents_protect()');
            DB::unprepared('CREATE TRIGGER security_audit_intents_no_truncate BEFORE TRUNCATE ON security_audit_intents FOR EACH STATEMENT EXECUTE FUNCTION security_audit_intents_protect()');
        } elseif (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($column) => "NEW.$column IS NOT OLD.$column", $columns));
            DB::unprepared("CREATE TRIGGER security_audit_intents_no_mutation BEFORE UPDATE ON security_audit_intents WHEN $changed BEGIN SELECT RAISE(ABORT, 'security audit intent payload is immutable'); END");
            DB::unprepared("CREATE TRIGGER security_audit_intents_no_delete BEFORE DELETE ON security_audit_intents BEGIN SELECT RAISE(ABORT, 'security audit intent payload is immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $changed = implode(' OR ', array_map(fn ($column) => "NOT (NEW.$column <=> OLD.$column)", $columns));
            DB::unprepared("CREATE TRIGGER security_audit_intents_no_mutation BEFORE UPDATE ON security_audit_intents FOR EACH ROW BEGIN IF $changed THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security audit intent payload is immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER security_audit_intents_no_delete BEFORE DELETE ON security_audit_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security audit intent payload is immutable'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('security_audit_intents');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS security_audit_intents_protect()');
        }
    }
};
