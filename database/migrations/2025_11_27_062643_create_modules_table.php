<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_modules_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('program_id');
            $table->foreign('program_id')->references('id')->on('programs')->onDelete('cascade');

            $table->string('title');
            $table->text('description');
            $table->enum('content_type', ['text', 'video']);
            $table->text('exercise')->nullable();
            $table->json('tracking_tools')->nullable();

            $table->integer('order')->default(0); // ترتيب الوحدة في البرنامج

            $table->timestamps();

            // Indexes
            $table->index('program_id');
            $table->index('content_type');
            $table->index('order');
        });
    }

    public function down()
    {
        Schema::dropIfExists('modules');
    }
};
