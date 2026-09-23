<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1:1 chat between a patient and the therapist assigned to them.
 *
 *  - conversations            one row per (patient, therapist) pair; closed when the
 *                             pair is no longer the active care relationship
 *  - messages.conversation_id every message belongs to exactly one conversation
 *  - messages.content         nullable so an attachment can be sent alone;
 *                             encrypted at rest by the model cast
 *  - messages.attachment_*    metadata of the private upload referenced by file_path
 *  - messages.read_at         read receipt timestamp (is_read kept for compatibility)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients', 'user_id')->cascadeOnDelete();
            $table->foreignUuid('therapist_id')->constrained('therapists', 'user_id')->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['patient_id', 'therapist_id']);
            $table->index(['therapist_id', 'last_message_at']);
            $table->index(['patient_id', 'last_message_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignUuid('conversation_id')->nullable()->constrained('conversations')->cascadeOnDelete();
            $table->text('content')->nullable()->change();
            $table->string('attachment_type', 16)->nullable();
            $table->string('attachment_name', 160)->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->index(['conversation_id', 'timestamp']);
            $table->index(['conversation_id', 'receiver_id', 'is_read']);
        });

        $this->addChecks();
    }

    public function down(): void
    {
        $this->dropChecks();

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'receiver_id', 'is_read']);
            $table->dropIndex(['conversation_id', 'timestamp']);
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropColumn(['attachment_type', 'attachment_name', 'attachment_mime', 'attachment_size', 'read_at']);
        });

        Schema::dropIfExists('conversations');
    }

    /** @return array<string, array{0:string,1:string}> */
    private function checks(): array
    {
        return [
            'conversations_status_check' => ['conversations', "status IN ('active','closed')"],
            'messages_attachment_type_check' => ['messages', "attachment_type IS NULL OR attachment_type IN ('image','audio','file')"],
            'messages_has_body_or_attachment' => ['messages', 'content IS NOT NULL OR file_path IS NOT NULL'],
        ];
    }

    /** SQLite (tests) cannot ADD CONSTRAINT; the application layer enforces these there. */
    private function addChecks(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach ($this->checks() as $name => [$table, $expr]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expr})");
        }
    }

    private function dropChecks(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach ($this->checks() as $name => [$table]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
