<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_withdrawals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('therapist_id')->references('user_id')->on('therapists')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | paid
            $table->text('payout_details')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('reviewer_id')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['therapist_id', 'status']);
        });

        // One open withdrawal request per therapist at a time.
        DB::statement(
            'CREATE UNIQUE INDEX wallet_withdrawals_pending_unique
             ON wallet_withdrawals (therapist_id)
             WHERE status = \'pending\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_withdrawals');
    }
};
