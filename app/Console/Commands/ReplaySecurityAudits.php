<?php

namespace App\Console\Commands;

use App\Models\SecurityAuditIntent;
use App\Services\Auth\ReplayAuditService;
use Illuminate\Console\Command;

class ReplaySecurityAudits extends Command
{
    protected $signature = 'security-audit:replay {--limit=100}';

    protected $description = 'Deliver pending replay incident audits without duplicate append-only records';

    public function handle(ReplayAuditService $audit): int
    {
        $ids = SecurityAuditIntent::whereNull('delivered_at')->where('next_attempt_at', '<=', now())
            ->orderBy('occurred_at')->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');
        $failed = 0;
        foreach ($ids as $id) {
            if (! $audit->deliver($id)) {
                $failed++;
            }
        }
        $pending = SecurityAuditIntent::whereNull('delivered_at')->count();
        $this->line("Attempted: {$ids->count()}; failed: $failed; pending: $pending.");

        return $pending > 0 ? self::FAILURE : self::SUCCESS;
    }
}
