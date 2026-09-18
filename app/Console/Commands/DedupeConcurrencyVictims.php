<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for rows created before the partial unique indexes existed
 * (double-booked slots, duplicate pending proofs/subscriptions). Keeps the
 * earliest row of each group and cancels/rejects the rest — nothing is
 * deleted.
 */
class DedupeConcurrencyVictims extends Command
{
    protected $signature = 'sakina:dedupe {--dry-run : Only report what would change}';

    protected $description = 'Resolve duplicate active sessions / pending payments / pending subscriptions prior to adding unique indexes';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;

        $total += $this->resolve(
            'therapy_sessions',
            ['therapist_id', 'session_date', 'session_time'],
            fn ($q) => $q->where('status', '<>', 'cancelled'),
            ['status' => 'cancelled'],
            $dry,
        );

        $total += $this->resolve(
            'payments',
            ['therapy_session_id'],
            fn ($q) => $q->where('status', 'pending')->whereNotNull('therapy_session_id'),
            ['status' => 'rejected', 'note' => 'Duplicate submission (auto-resolved)'],
            $dry,
        );

        $total += $this->resolve(
            'payments',
            ['subscription_id'],
            fn ($q) => $q->where('status', 'pending')->whereNotNull('subscription_id'),
            ['status' => 'rejected', 'note' => 'Duplicate submission (auto-resolved)'],
            $dry,
        );

        $total += $this->resolve(
            'subscriptions',
            ['patient_id'],
            fn ($q) => $q->where('verification_status', 'pending'),
            ['verification_status' => 'rejected'],
            $dry,
        );

        $this->info(($dry ? 'Would change ' : 'Changed ').$total.' row(s).');

        return self::SUCCESS;
    }

    private function resolve(string $table, array $keys, callable $scope, array $update, bool $dry): int
    {
        $groups = $scope(DB::table($table))
            ->select($keys)
            ->groupBy($keys)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $changed = 0;

        foreach ($groups as $group) {
            $rows = $scope(DB::table($table));
            foreach ($keys as $k) {
                $rows->where($k, $group->{$k});
            }
            $ids = $rows->orderBy('created_at')->orderBy('id')->pluck('id');
            $losers = $ids->slice(1)->values();

            if ($losers->isEmpty()) {
                continue;
            }

            $this->line(sprintf('%s %s: keep %s, %s %d duplicate(s)',
                $table, json_encode((array) $group), $ids->first(), $dry ? 'would update' : 'updating', $losers->count()));

            if (! $dry) {
                DB::table($table)->whereIn('id', $losers->all())->update($update + ['updated_at' => now()]);
            }

            $changed += $losers->count();
        }

        return $changed;
    }
}
