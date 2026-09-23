<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_subscriptions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('user_id')->on('patients')->onDelete('cascade');

            $table->enum('type', ['4_weeks', '8_weeks']);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('price', 8, 2);
            $table->string('payment_proof_path')->nullable();
            $table->enum('verification_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->json('content')->nullable(); // محتوى البرنامج

            $table->timestamps();

            // Indexes
            $table->index('patient_id');
            $table->index('verification_status');
            $table->index('type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('subscriptions');
    }
};
