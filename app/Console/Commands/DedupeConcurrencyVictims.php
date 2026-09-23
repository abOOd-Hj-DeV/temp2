<?php

namespace App\Console\Commands;

use App\Models\Assessment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for rows created before the partial unique indexes existed
 * (double-booked slots, duplicate pending proofs/subscriptions/switch requests,
 * duplicate open red flags). Keeps the earliest row of each group and
 * cancels/rejects/resolves the rest — nothing is deleted by default.
 *
 * Two groups cannot be resolved by a status change and are only *reported*
 * unless the matching opt-in flag is passed:
 *  - identical duplicate assessments (same patient/instrument/day/score/answers)
 *    → `--purge-identical-assessments` deletes the later copies;
 *  - e-mail collisions where the later account is still unverified
 *    → `--purge-unverified-emails` deletes that placeholder registration.
 * Anything else (differing assessments, two verified accounts sharing an
 * e-mail) requires a human decision and keeps the migration blocked.
 */
class DedupeConcurrencyVictims extends Command
{
    protected $signature = 'sakina:dedupe
        {--dry-run : Only report what would change}
        {--purge-identical-assessments : Delete later copies of byte-identical duplicate assessments}
        {--purge-unverified-emails : Delete unverified accounts whose e-mail collides case-insensitively with another account}';

    protected $description = 'Resolve duplicates (sessions, payments, subscriptions, switch requests, red flags, assessments, e-mails) prior to adding root unique indexes';

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

        $total += $this->resolve(
            'therapist_switches',
            ['patient_id'],
            fn ($q) => $q->where('status', 'requested'),
            ['status' => 'rejected'],
            $dry,
        );

        $total += $this->mergeOpenRedFlags($dry);
        $total += $this->purgeIdenticalAssessments($dry);
        $total += $this->purgeUnverifiedEmailCollisions($dry);

        $this->info(($dry ? 'Would change ' : 'Changed ').$total.' row(s).');

        return self::SUCCESS;
    }

    /**
     * Keep the earliest open flag per (patient, type); resolve the others and
     * point them at the survivor. The survivor inherits the highest priority.
     */
    private function mergeOpenRedFlags(bool $dry): int
    {
        $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
        $changed = 0;

        $groups = DB::table('red_flags')->select('patient_id', 'type')->where('status', 'open')
            ->groupBy('patient_id', 'type')->havingRaw('COUNT(*) > 1')->get();

        foreach ($groups as $group) {
            $rows = DB::table('red_flags')->where('status', 'open')
                ->where('patient_id', $group->patient_id)->where('type', $group->type)
                ->orderBy('created_at')->orderBy('id')->get(['id', 'priority']);
            $keep = $rows->first();
            $losers = $rows->slice(1);
            $top = $rows->sortByDesc(fn ($r) => $rank[$r->priority] ?? 0)->first()->priority;

            $this->line(sprintf('red_flags %s: keep %s, %s %d duplicate(s)',
                json_encode((array) $group), $keep->id, $dry ? 'would resolve' : 'resolving', $losers->count()));

            if (! $dry) {
                DB::table('red_flags')->whereIn('id', $losers->pluck('id')->all())->update([
                    'status' => 'resolved',
                    'action_taken' => "Duplicate merged into red flag {$keep->id} (auto-resolved)",
                    'updated_at' => now(),
                ]);
                if ($top !== $keep->priority) {
                    DB::table('red_flags')->where('id', $keep->id)->update(['priority' => $top, 'updated_at' => now()]);
                }
            }

            $changed += $losers->count();
        }

        return $changed;
    }

    private function purgeIdenticalAssessments(bool $dry): int
    {
        $purge = (bool) $this->option('purge-identical-assessments');
        $changed = 0;

        $groups = DB::table('assessments')->selectRaw('patient_id, type, date(completed_at) as day')
            ->groupBy('patient_id', 'type', DB::raw('date(completed_at)'))->havingRaw('COUNT(*) > 1')->get();

        foreach ($groups as $group) {
            // Answers are encrypted per row, so compare plaintext through the model.
            $rows = Assessment::query()->where('patient_id', $group->patient_id)->where('type', $group->type)
                ->whereRaw('date(completed_at) = ?', [$group->day])
                ->orderBy('completed_at')->orderBy('id')->get();
            $keep = $rows->first();
            $identical = $rows->slice(1)->filter(fn ($r) => $r->score === $keep->score
                && $r->answers !== null && $r->answers === $keep->answers);
            $differing = $rows->slice(1)->count() - $identical->count();

            if ($differing > 0) {
                $this->warn(sprintf('assessments %s: %d duplicate(s) differ from %s — manual review required, not touched',
                    json_encode((array) $group), $differing, $keep->id));
            }

            if ($identical->isEmpty()) {
                continue;
            }

            if (! $purge) {
                $this->warn(sprintf('assessments %s: %d identical duplicate(s) of %s — re-run with --purge-identical-assessments to delete',
                    json_encode((array) $group), $identical->count(), $keep->id));

                continue;
            }

            $this->line(sprintf('assessments %s: keep %s, %s %d identical duplicate(s)',
                json_encode((array) $group), $keep->id, $dry ? 'would delete' : 'deleting', $identical->count()));

            if (! $dry) {
                DB::table('red_flags')->whereIn('assessment_id', $identical->pluck('id')->all())->update(['assessment_id' => $keep->id]);
                DB::table('assessments')->whereIn('id', $identical->pluck('id')->all())->delete();
            }

            $changed += $identical->count();
        }

        return $changed;
    }

    private function purgeUnverifiedEmailCollisions(bool $dry): int
    {
        $purge = (bool) $this->option('purge-unverified-emails');
        $changed = 0;

        $groups = DB::table('users')->selectRaw('lower(email) as e')
            ->groupBy(DB::raw('lower(email)'))->havingRaw('COUNT(*) > 1')->pluck('e');

        foreach ($groups as $email) {
            $rows = DB::table('users')->whereRaw('lower(email) = ?', [$email])
                ->orderBy('created_at')->orderBy('id')->get(['id', 'email', 'is_active', 'phone_verified_at']);
            $keep = $rows->first(fn ($r) => $r->phone_verified_at !== null) ?? $rows->first();
            $others = $rows->where('id', '<>', $keep->id);
            $unverified = $others->filter(fn ($r) => $r->phone_verified_at === null && ! $r->is_active);
            $verified = $others->count() - $unverified->count();

            if ($verified > 0) {
                $this->warn(sprintf('users %s: %d other verified account(s) share this e-mail — manual review required, not touched',
                    json_encode($email), $verified));
            }

            if ($unverified->isEmpty()) {
                continue;
            }

            if (! $purge) {
                $this->warn(sprintf('users %s: %d unverified placeholder(s) collide with %s — re-run with --purge-unverified-emails to delete',
                    json_encode($email), $unverified->count(), $keep->id));

                continue;
            }

            $this->line(sprintf('users %s: keep %s, %s %d unverified duplicate(s)',
                json_encode($email), $keep->id, $dry ? 'would delete' : 'deleting', $unverified->count()));

            if (! $dry) {
                DB::table('users')->whereIn('id', $unverified->pluck('id')->all())->delete();
            }

            $changed += $unverified->count();
        }

        return $changed;
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
