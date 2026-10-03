<?php

namespace App\Console\Commands;

use App\Services\Patient\ClinicalEncryption;
use Illuminate\Console\Command;

class EncryptClinicalText extends Command
{
    protected $signature = 'clinical:encrypt-text {--rotate : Re-encrypt using the current key after configuring previous keys}';

    protected $description = 'Backfill legacy clinical plaintext or rotate clinical ciphertext';

    public function handle(ClinicalEncryption $encryption): int
    {
        $encryption->backfill((bool) $this->option('rotate'));

        return self::SUCCESS;
    }
}
