<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when a payment proof is uploaded — alerts the staff members
 * responsible for the manual review (finance, admins).
 */
class ReviewPaymentProofJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private string $paymentId) {}

    public function handle(NotificationService $notifications): void
    {
        $payment = Payment::find($this->paymentId);

        if (! $payment) {
            return;
        }

        $reviewers = User::whereIn('role', [
            UserRole::FINANCE_PARTNER->value,
            UserRole::ADMIN->value,
            UserRole::SUPER_ADMIN->value,
        ])
            ->where('is_active', true)
            ->get();

        $notifications->paymentProofSubmitted($payment, $reviewers);
    }
}
