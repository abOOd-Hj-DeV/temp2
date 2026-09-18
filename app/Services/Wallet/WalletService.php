<?php

namespace App\Services\Wallet;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Exceptions\ConflictException;
use App\Models\Therapist;
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
 *                and PAID for this therapist
 *   pending    = same formula over PAID sessions not yet completed
 *   withdrawn  = Σ withdrawals in approved|paid
 *   reserved   = Σ withdrawals still pending review
 *   available  = earned − withdrawn − reserved
 *
 * Free (subscription-covered) sessions carry price 0 and therefore add
 * nothing; revenue share for subscriptions is a business decision that is
 * intentionally *not* invented here.
 */
class WalletService
{
    public function __construct(private AuditLogService $audit) {}

    public function summary(Therapist $therapist): array
    {
        $rate = $this->commissionRate();
        $earnedGross = $this->paidSessions($therapist)->where('status', SessionStatus::COMPLETED->value)->sum('price');
        $pendingGross = $this->paidSessions($therapist)->where('status', '!=', SessionStatus::COMPLETED->value)->sum('price');

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
            'min_withdrawal' => (float) config('sakina.min_withdrawal_amount', 50),
            'completed_paid_sessions' => $this->paidSessions($therapist)->where('status', SessionStatus::COMPLETED->value)->count(),
            'recent_withdrawals' => $therapist->withdrawals()->latest()->limit(10)->get()
                ->map(fn (WalletWithdrawal $w) => $this->withdrawalToArray($w))->all(),
        ];
    }

    public function requestWithdrawal(Therapist $therapist, float $amount, array $payoutDetails, User $actor): WalletWithdrawal
    {
        $min = (float) config('sakina.min_withdrawal_amount', 50);

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

    private function paidSessions(Therapist $therapist)
    {
        return $therapist->sessions()
            ->where('payment_status', PaymentStatus::PAID->value)
            ->where('status', '!=', SessionStatus::CANCELLED->value);
    }

    private function commissionRate(): float
    {
        return min(1.0, max(0.0, (float) config('sakina.platform_commission_rate', 0.2)));
    }
}
