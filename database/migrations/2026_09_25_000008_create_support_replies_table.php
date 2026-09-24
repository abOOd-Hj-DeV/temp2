<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Threaded replies inside a support ticket (patient <-> support staff). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('support_id');
            $table->foreign('support_id')->references('id')->on('supports')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->boolean('is_staff')->default(false);
            $table->text('body');
            $table->timestamps();

            $table->index(['support_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_replies');
    }
};
