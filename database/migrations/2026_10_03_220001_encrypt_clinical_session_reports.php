<?php

use App\Services\Patient\ClinicalEncryption;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ClinicalEncryption::class)->backfillSessionReports();
    }

    public function down(): void
    {
        // Report bodies must never be reverted to plaintext.
    }
};
