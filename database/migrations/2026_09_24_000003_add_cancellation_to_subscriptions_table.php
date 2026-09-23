<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('verification_status');
            $table->foreignUuid('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
        });

        // SQLite rebuilds the table to add a foreign key and drops the WHERE
        // clause of its partial unique index; restore it.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS subscriptions_pending_per_patient_unique');
            DB::statement("CREATE UNIQUE INDEX subscriptions_pending_per_patient_unique ON subscriptions (patient_id) WHERE verification_status = 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
