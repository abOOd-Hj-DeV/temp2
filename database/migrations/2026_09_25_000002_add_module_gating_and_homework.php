<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->longText('body')->nullable()->after('content_type');
            $table->string('media_url', 2000)->nullable()->after('body');
            $table->text('homework_prompt')->nullable()->after('exercise');
            $table->boolean('is_hideable')->default(false)->after('order');
        });

        Schema::table('patient_modules', function (Blueprint $table) {
            $table->json('homework')->nullable()->after('status');
            $table->timestamp('homework_submitted_at')->nullable()->after('homework');
            $table->uuid('hidden_by')->nullable()->after('homework_submitted_at');
            $table->timestamp('hidden_at')->nullable()->after('hidden_by');
        });
    }

    public function down(): void
    {
        Schema::table('patient_modules', function (Blueprint $table) {
            $table->dropColumn(['homework', 'homework_submitted_at', 'hidden_by', 'hidden_at']);
        });

        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['body', 'media_url', 'homework_prompt', 'is_hideable']);
        });
    }
};
