<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_supports_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('supports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->enum('type', ['technical', 'clinical']);
            $table->text('description');
            $table->string('file_path')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');

            $table->uuid('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');

            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('assigned_to');
            $table->index('status');
            $table->index('type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('supports');
    }
};
