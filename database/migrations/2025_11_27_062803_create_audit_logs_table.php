<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_audit_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->string('action');
            $table->uuid('entity_id')->nullable(); // ID الكيان المتعلق
            $table->json('details')->nullable();
            $table->timestamp('timestamp')->useCurrent();

            // Indexes
            $table->index('user_id');
            $table->index('action');
            $table->index('entity_id');
            $table->index('timestamp');
        });
    }

    public function down()
    {
        Schema::dropIfExists('audit_logs');
    }
};
