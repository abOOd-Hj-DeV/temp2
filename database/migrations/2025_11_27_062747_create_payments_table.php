<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_payments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('subscription_id');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('cascade');

            $table->decimal('amount', 10, 2);
            $table->string('proof_file_path');
            $table->uuid('reviewer_id')->nullable(); // المسؤول المراجع
            $table->foreign('reviewer_id')->references('id')->on('users')->onDelete('set null');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('note')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('subscription_id');
            $table->index('reviewer_id');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payments');
    }
};
