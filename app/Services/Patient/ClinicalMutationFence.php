<?php

namespace App\Services\Patient;

use App\Services\Files\AccountFileFence;
use Illuminate\Support\Facades\DB;

/** Owner locks precede clinical rows and stay held until the write commits. */
class ClinicalMutationFence
{
    public static function run(string|array $patientIds, callable $operation): mixed
    {
        return DB::transaction(function () use ($patientIds, $operation) {
            AccountFileFence::lock((array) $patientIds);

            return $operation();
        });
    }
}
