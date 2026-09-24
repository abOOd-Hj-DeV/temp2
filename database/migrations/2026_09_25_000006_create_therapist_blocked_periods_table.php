<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vacations / closed days that override a therapist's recurring weekly
 * availability. Dates are calendar days in the therapist's own timezone,
 * inclusive on both ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapist_blocked_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('therapist_id');
            $table->foreign('therapist_id')->references('user_id')->on('therapists')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason', 200)->nullable();
            $table->timestamps();

            $table->index(['therapist_id', 'start_date', 'end_date'], 'therapist_blocked_periods_range_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE therapist_blocked_periods ADD CONSTRAINT therapist_blocked_periods_range_check CHECK (end_date >= start_date)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('therapist_blocked_periods');
    }
};
