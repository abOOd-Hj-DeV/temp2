<?php

namespace App\Console\Commands;

use App\Services\Account\StaffAccountService;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * First-run only: creates the platform's first super_admin from the server
 * (SSH access is the proof of authority). No password is printed or stored;
 * a one-time activation code is sent to the given WhatsApp number and the
 * command refuses to run once any super_admin exists.
 */
class BootstrapSuperAdmin extends Command
{
    protected $signature = 'sakina:bootstrap-super-admin
        {--name= : Display name of the first super admin}
        {--email= : E-mail address}
        {--whatsapp= : WhatsApp number that will receive the activation code}';

    protected $description = 'Create the first super_admin account and send its one-time activation code';

    public function handle(StaffAccountService $accounts): int
    {
        $name = trim((string) ($this->option('name') ?? ''));
        $email = mb_strtolower(trim((string) ($this->option('email') ?? '')));
        $whatsapp = PhoneNumber::normalize($this->option('whatsapp'));

        if ($name === '' || $email === '' || $whatsapp === null) {
            $this->error('--name, --email and --whatsapp are all required.');

            return self::INVALID;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! preg_match('/^\+[0-9]{8,15}$/', $whatsapp)) {
            $this->error('Invalid e-mail address or WhatsApp number.');

            return self::INVALID;
        }

        try {
            $result = $accounts->bootstrapSuperAdmin([
                'name' => $name,
                'email' => $email,
                'whatsapp_number' => $whatsapp,
            ]);
        } catch (ValidationException $e) {
            $this->error(implode(' ', array_map(fn ($m) => implode(' ', $m), $e->errors())));

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'super_admin %s created. An activation code valid until %s was sent to %s; use POST /api/v1/auth/activate to set the password.',
            $result['user']->id,
            $result['expires_at']->toDateTimeString(),
            $whatsapp,
        ));

        return self::SUCCESS;
    }
}
