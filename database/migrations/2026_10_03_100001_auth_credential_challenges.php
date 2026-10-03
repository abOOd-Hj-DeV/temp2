<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('credential_version')->default(1);
        });
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('credential_version')->default(1);
        });
        Schema::create('auth_login_challenges', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('credential_version');
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_login_challenges');
        Schema::table('refresh_tokens', fn (Blueprint $table) => $table->dropColumn('credential_version'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('credential_version'));
    }
};
