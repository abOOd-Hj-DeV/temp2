<?php

namespace App\Console\Commands;

use App\Services\Notifications\OperationsHealth;
use Illuminate\Console\Command;

class OperationsHealthCheck extends Command
{
    protected $signature = 'ops:health {role=app}';

    protected $description = 'Check readiness or freshness of a worker/scheduler heartbeat';

    public function handle(OperationsHealth $health): int
    {
        $role = $this->argument('role');
        if (! in_array($role, ['app', 'worker', 'scheduler'], true)) {
            $this->error('Role must be app, worker or scheduler.');

            return self::INVALID;
        }

        $ready = $role === 'app' ? $health->ready() : $health->heartbeatFresh($role);
        $this->line($ready ? 'healthy' : 'unavailable');

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
