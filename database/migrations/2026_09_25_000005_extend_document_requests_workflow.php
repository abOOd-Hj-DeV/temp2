<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document requests become a real workflow: staff ask a therapist for a
 * document (status=requested, no file yet), the therapist uploads it
 * (submitted), staff approve or reject it (rejected allows re-upload).
 */
return new class extends Migration
{
    private const STATUSES = ['requested', 'submitted', 'approved', 'rejected'];

    private const DOC_TYPES = ['license', 'identity', 'certificate', 'payment_proof', 'support_file', 'other'];

    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->string('doc_type', 32)->change();
            $table->string('status', 32)->default('requested')->change();
            $table->string('file_path')->nullable()->change();
        });

        Schema::table('document_requests', function (Blueprint $table) {
            $table->foreignUuid('requested_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('original_name', 160)->nullable()->after('file_path');
            $table->string('mime_type', 100)->nullable()->after('original_name');
            $table->text('review_note')->nullable()->after('reason');
            $table->timestamp('submitted_at')->nullable()->after('reviewer_id');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE document_requests DROP CONSTRAINT IF EXISTS document_requests_status_check');
            DB::statement('ALTER TABLE document_requests DROP CONSTRAINT IF EXISTS document_requests_doc_type_check');
            DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_status_check CHECK ('.$this->in('status', self::STATUSES).')');
            DB::statement('ALTER TABLE document_requests ADD CONSTRAINT document_requests_doc_type_check CHECK ('.$this->in('doc_type', self::DOC_TYPES).')');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE document_requests DROP CONSTRAINT IF EXISTS document_requests_status_check');
            DB::statement('ALTER TABLE document_requests DROP CONSTRAINT IF EXISTS document_requests_doc_type_check');
        }

        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn([
                'original_name', 'mime_type', 'review_note', 'submitted_at', 'reviewed_at', 'created_at', 'updated_at',
            ]);
        });
    }

    private function in(string $column, array $values): string
    {
        return $column." IN ('".implode("','", $values)."')";
    }
};
