<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the plaintext-derived SHA-256 digests previously stored next to each
 * encrypted assessment answer; they made the ciphertext trivially reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assessments')->select(['id', 'answers'])->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                $answers = json_decode((string) $row->answers, true);

                if (! is_array($answers)) {
                    continue;
                }

                $changed = false;

                foreach ($answers as $key => $item) {
                    if (is_array($item) && array_key_exists('hash', $item)) {
                        unset($answers[$key]['hash']);
                        $changed = true;
                    }
                }

                if ($changed) {
                    DB::table('assessments')->where('id', $row->id)->update(['answers' => json_encode($answers)]);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: re-adding the digests would reintroduce the leak.
    }
};
