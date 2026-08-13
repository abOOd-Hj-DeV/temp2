<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_messages_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('sender_id');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');

            $table->uuid('receiver_id');
            $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');

            $table->text('content');
            $table->string('file_path')->nullable();
            $table->timestamp('timestamp')->useCurrent();
            $table->boolean('is_read')->default(false);

            // Indexes
            $table->index('sender_id');
            $table->index('receiver_id');
            $table->index('timestamp');
            $table->index('is_read');

            // Index مركب للاستعلامات السريعة
            $table->index(['sender_id', 'receiver_id']);
            $table->index(['receiver_id', 'sender_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('messages');
    }
};
