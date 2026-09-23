<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_therapist_switches_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('therapist_switches', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->uuid('old_therapist_id');
            $table->foreign('old_therapist_id')->references('user_id')->on('therapists')->onDelete('cascade');

            $table->uuid('new_therapist_id');
            $table->foreign('new_therapist_id')->references('user_id')->on('therapists')->onDelete('cascade');

            $table->uuid('subscription_id');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('cascade');

            $table->text('reason');
            $table->timestamp('timestamp')->useCurrent();
            $table->enum('status', ['requested', 'approved', 'rejected'])->default('requested');

            $table->timestamps();

            // Indexes
            $table->index('patient_id');
            $table->index('old_therapist_id');
            $table->index('new_therapist_id');
            $table->index('subscription_id');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('therapist_switches');
    }
};
