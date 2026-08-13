<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_document_requests_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->enum('doc_type', ['license', 'payment_proof', 'support_file']);
            $table->string('file_path');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->uuid('reviewer_id')->nullable();
            $table->foreign('reviewer_id')->references('id')->on('users')->onDelete('set null');

            $table->timestamp('timestamp')->useCurrent();

            // Indexes
            $table->index('user_id');
            $table->index('reviewer_id');
            $table->index('status');
            $table->index('doc_type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_requests');
    }
};
