<?php

namespace App\Jobs;

use App\Enums\PaymentReviewStatus;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Payment proofs that nobody reviewed within the SLA (default 12h) get one
 * in-app + WhatsApp reminder to finance/admin staff. The reminder is claimed
 * atomically so overlapping runs never double-notify.
 */
class RemindStalePaymentReviewsJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationService $notifications): void
    {
        $slaHours = (int) config('sakina.payment_review_sla_hours', 12);
        $cutoff = now()->subHours($slaHours);

        $stale = Payment::query()
            ->where('status', PaymentReviewStatus::PENDING->value)
            ->whereNull('review_reminder_sent_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $reviewers = User::whereIn('role', [
            UserRole::FINANCE_PARTNER->value,
            UserRole::ADMIN->value,
            UserRole::SUPER_ADMIN->value,
        ])->where('is_active', true)->get();

        foreach ($stale as $payment) {
            $claimed = Payment::whereKey($payment->id)
                ->whereNull('review_reminder_sent_at')
                ->update(['review_reminder_sent_at' => now()]);

            if ($claimed === 0) {
                continue;
            }

            $pendingHours = (int) $payment->created_at->diffInHours(now());

            try {
                $notifications->paymentReviewOverdue($payment, $reviewers, $pendingHours);
            } catch (\Throwable $e) {
                Payment::whereKey($payment->id)->update(['review_reminder_sent_at' => null]);
                Log::error('Payment review reminder failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
