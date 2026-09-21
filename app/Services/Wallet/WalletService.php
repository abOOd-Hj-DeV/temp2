<?php

namespace App\Services\Wallet;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Exceptions\ConflictException;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapySession;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Services\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Therapist wallet, derived (not stored) from the ledger of record:
 *
 *   earned     = Σ price × (1 − commission) over sessions that are COMPLETED
 *                and PAID for this therapist, plus Σ (package price ÷ sessions_total)
 *                × (1 − commission) for every package session this therapist
 *                completed with confirmed attendance
 *   pending    = same formulas over PAID sessions not yet completed and the
 *                undelivered part of live packages currently assigned to this
 *                therapist (a cancelled package has no pending value: it is not
 *                refunded and its remainder is not owed to anyone)
 *   withdrawn  = Σ withdrawals in approved|paid
 *   reserved   = Σ withdrawals still pending review
 *   available  = earned − withdrawn − reserved
 *
 * A package is priced as a whole: its covered sessions carry price 0 and the
 * therapist's share is taken from the package price in equal per-session
 * slices, so a mid-package therapist change splits the revenue by the
 * sessions each therapist actually delivered.
 */
class WalletService
{
    public function __construct(private AuditLogService $audit) {}

    public function summary(Therapist $therapist): array
    {
        $rate = $this->commissionRate();
        $earnedPackageGross = $this->earnedPackageGross($therapist);
        $earnedGross = (float) $this->earnedSessions($therapist)->sum('price') + $earnedPackageGross;
        $pendingGross = (float) $this->paidSessions($therapist)->sum('price')
            - (float) $this->earnedSessions($therapist)->sum('price')
            + $this->pendingPackageGross($therapist);

        $earned = round((float) $earnedGross * (1 - $rate), 2);
        $pending = round((float) $pendingGross * (1 - $rate), 2);
        $withdrawn = (float) $therapist->withdrawals()
            ->whereIn('status', [WalletWithdrawal::STATUS_APPROVED, WalletWithdrawal::STATUS_PAID])->sum('amount');
        $reserved = (float) $therapist->withdrawals()
            ->where('status', WalletWithdrawal::STATUS_PENDING)->sum('amount');

        return [
            'currency' => config('sakina.currency', 'USD'),
            'commission_rate' => $rate,
            'earned' => $earned,
            'pending_earnings' => $pending,
            'withdrawn' => round($withdrawn, 2),
            'reserved' => round($reserved, 2),
            'available' => round(max(0, $earned - $withdrawn - $reserved), 2),
            'min_withdrawal' => (float) config('sakina.min_withdrawal_amount', 20),
            'completed_paid_sessions' => $this->earnedSessions($therapist)->count(),
            'earned_package_sessions' => $this->earnedPackageSessions($therapist)->count(),
            'recent_withdrawals' => $therapist->withdrawals()->latest()->limit(10)->get()
                ->map(fn (WalletWithdrawal $w) => $this->withdrawalToArray($w))->all(),
        ];
    }

    public function requestWithdrawal(Therapist $therapist, float $amount, array $payoutDetails, User $actor): WalletWithdrawal
    {
        $min = (float) config('sakina.min_withdrawal_amount', 20);

        if ($amount < $min) {
            throw ValidationException::withMessages(['amount' => "Minimum withdrawal is {$min}."]);
        }

        try {
            $withdrawal = DB::transaction(function () use ($therapist, $amount, $payoutDetails) {
                $locked = Therapist::whereKey($therapist->user_id)->lockForUpdate()->firstOrFail();

                if ($locked->withdrawals()->where('status', WalletWithdrawal::STATUS_PENDING)->exists()) {
                    throw new ConflictException('You already have a withdrawal request pending review.');
                }

                $available = $this->summary($locked)['available'];

                if ($amount > $available) {
                    throw ValidationException::withMessages(['amount' => "Insufficient balance. Available: {$available}."]);
                }

                return WalletWithdrawal::create([
                    'therapist_id' => $locked->user_id,
                    'amount' => $amount,
                    'status' => WalletWithdrawal::STATUS_PENDING,
                    'payout_details' => $payoutDetails,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictException('You already have a withdrawal request pending review.');
        }

        $this->audit->record($actor, AuditLogService::WITHDRAWAL_REQUESTED, $withdrawal->id, ['amount' => $amount]);

        return $withdrawal;
    }

    /**
     * Finance reviews a withdrawal: approve | reject | paid (approved → paid).
     */
    public function review(WalletWithdrawal $withdrawal, string $action, User $reviewer, ?string $note = null): WalletWithdrawal
    {
        $withdrawal = DB::transaction(function () use ($withdrawal, $action, $reviewer, $note) {
            $locked = WalletWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            $allowed = match ($action) {
                'approve', 'reject' => [WalletWithdrawal::STATUS_PENDING],
                'paid' => [WalletWithdrawal::STATUS_APPROVED],
                default => [],
            };

            if (! in_array($locked->status, $allowed, true)) {
                throw new ConflictException("Cannot {$action} a withdrawal in status '{$locked->status}'.");
            }

            if ($action === 'reject' && ! $note) {
                throw ValidationException::withMessages(['note' => 'A note is required when rejecting.']);
            }

            $locked->update([
                'status' => match ($action) {
                    'approve' => WalletWithdrawal::STATUS_APPROVED,
                    'reject' => WalletWithdrawal::STATUS_REJECTED,
                    'paid' => WalletWithdrawal::STATUS_PAID,
                },
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'note' => $note ?? $locked->note,
            ]);

            $this->audit->record($reviewer, AuditLogService::WITHDRAWAL_DECIDED, $locked->id, [
                'action' => $action, 'amount' => (float) $locked->amount,
            ]);

            return $locked->refresh();
        });

        return $withdrawal;
    }

    public function withdrawalToArray(WalletWithdrawal $w): array
    {
        return [
            'id' => $w->id,
            'therapist_id' => $w->therapist_id,
            'amount' => (float) $w->amount,
            'status' => $w->status,
            'payout_details' => $w->payout_details,
            'note' => $w->note,
            'reviewed_at' => $w->reviewed_at?->toISOString(),
            'created_at' => $w->created_at?->toISOString(),
        ];
    }

    /**
     * Only sessions the patient (or a supervisor) confirmed attending are
     * recognised as earnings; a therapist's own "completed" claim is not enough.
     */
    private function earnedSessions(Therapist $therapist)
    {
        return $this->paidSessions($therapist)
            ->where('status', SessionStatus::COMPLETED->value)
            ->whereNotNull('attendance_confirmed_at');
    }

    private function paidSessions(Therapist $therapist)
    {
        return $therapist->sessions()
            ->where('payment_status', PaymentStatus::PAID->value)
            ->where('status', '!=', SessionStatus::CANCELLED->value);
    }

    /** Package sessions this therapist delivered, regardless of who holds the package now. */
    private function earnedPackageSessions(Therapist $therapist)
    {
        return $therapist->sessions()
            ->where('status', SessionStatus::COMPLETED->value)
            ->whereNotNull('attendance_confirmed_at')
            ->whereHas('subscription', fn ($q) => $q->where('verification_status', 'approved'));
    }

    private function earnedPackageGross(Therapist $therapist): float
    {
        return $this->earnedPackageSessions($therapist)
            ->with('subscription:id,price,sessions_total')
            ->get()
            ->sum(fn (TherapySession $s) => self::sessionShare($s->subscription));
    }

    /**
     * Undelivered slices of live packages assigned to this therapist: the
     * package's remaining sessions after every therapist's delivered ones.
     */
    private function pendingPackageGross(Therapist $therapist): float
    {
        return Subscription::where('therapist_id', $therapist->user_id)
            ->where('verification_status', 'approved')
            ->whereNull('cancelled_at')
            ->whereDate('end_date', '>=', now()->toDateString())
            ->withCount(['sessions as delivered_count' => fn ($q) => $q
                ->where('status', SessionStatus::COMPLETED->value)
                ->whereNotNull('attendance_confirmed_at')])
            ->get()
            ->sum(fn (Subscription $s) => self::sessionShare($s)
                * max(0, (int) $s->sessions_total - (int) $s->delivered_count));
    }

    private static function sessionShare(?Subscription $subscription): float
    {
        if ($subscription === null || (int) $subscription->sessions_total <= 0) {
            return 0.0;
        }

        return (float) $subscription->price / (int) $subscription->sessions_total;
    }

    private function commissionRate(): float
    {
        return min(1.0, max(0.0, (float) config('sakina.platform_commission_rate', 0.2)));
    }
}
