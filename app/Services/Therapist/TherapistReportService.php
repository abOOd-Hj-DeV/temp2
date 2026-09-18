<?php

namespace App\Services\Therapist;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\Therapist;
use App\Models\TherapySession;
use Illuminate\Support\Carbon;

/**
 * Aggregated activity/earnings report for a therapist over a date range
 * (default: current month). Gross figures only; commission is applied in
 * WalletService.
 */
class TherapistReportService
{
    public function build(Therapist $therapist, ?string $from, ?string $to): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->startOfMonth();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now()->endOfMonth();

        $base = TherapySession::where('therapist_id', $therapist->user_id)
            ->whereBetween('session_date', [$fromDate->toDateString(), $toDate->toDateString()]);

        $byStatus = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $completedPaid = (clone $base)
            ->where('status', SessionStatus::COMPLETED->value)
            ->where('payment_status', PaymentStatus::PAID->value);

        $perDay = (clone $base)
            ->where('status', '!=', SessionStatus::CANCELLED->value)
            ->selectRaw('session_date, count(*) as c')
            ->groupBy('session_date')
            ->orderBy('session_date')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->session_date)->toDateString(),
                'sessions' => (int) $row->c,
            ])->all();

        $total = (int) $byStatus->sum();
        $cancelled = (int) ($byStatus[SessionStatus::CANCELLED->value] ?? 0);

        return [
            'period' => ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()],
            'sessions' => [
                'total' => $total,
                'pending' => (int) ($byStatus[SessionStatus::PENDING->value] ?? 0),
                'confirmed' => (int) ($byStatus[SessionStatus::CONFIRMED->value] ?? 0),
                'completed' => (int) ($byStatus[SessionStatus::COMPLETED->value] ?? 0),
                'cancelled' => $cancelled,
                'cancellation_rate' => $total > 0 ? round($cancelled / $total, 3) : 0,
                'free_initial' => (int) (clone $base)->where('payment_status', PaymentStatus::FREE->value)->count(),
            ],
            'clients' => [
                'distinct' => (int) (clone $base)->where('status', '!=', SessionStatus::CANCELLED->value)->distinct('patient_id')->count('patient_id'),
                'active_assigned' => $therapist->clients_count,
                'limit' => $therapist->clients_limit,
            ],
            'earnings' => [
                'currency' => config('sakina.currency', 'USD'),
                'gross_completed_paid' => round((float) $completedPaid->sum('price'), 2),
                'completed_paid_sessions' => (int) $completedPaid->count(),
            ],
            'per_day' => $perDay,
        ];
    }
}
