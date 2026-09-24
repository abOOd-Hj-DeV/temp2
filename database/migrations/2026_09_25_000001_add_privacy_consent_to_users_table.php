<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('privacy_policy_version', 64)->nullable()->after('timezone');
            $table->timestamp('privacy_accepted_at')->nullable()->after('privacy_policy_version');
            $table->timestamp('password_changed_at')->nullable()->after('privacy_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_policy_version', 'privacy_accepted_at', 'password_changed_at']);
        });
    }
};
