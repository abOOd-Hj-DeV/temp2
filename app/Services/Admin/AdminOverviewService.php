<?php

namespace App\Services\Admin;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentReviewStatus;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\RedFlag;
use App\Models\Subscription;
use App\Models\Therapist;
use App\Models\TherapistSwitch;
use App\Models\TherapySession;
use App\Models\User;
use App\Models\WalletWithdrawal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate counters for the admin dashboard. Every figure is a count or a
 * sum; no row-level data leaves this service.
 */
class AdminOverviewService
{
    public function overview(): array
    {
        $today = now()->toDateString();
        $weekEnd = now()->addDays(7)->toDateString();

        return [
            'generated_at' => now()->toISOString(),
            'users' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'pending_deletion' => User::whereNotNull('deletion_scheduled_at')->count(),
                'by_role' => $this->countBy(User::query(), 'role', UserRole::values()),
            ],
            'patients' => [
                'total' => Patient::count(),
                'with_therapist' => Patient::whereNotNull('therapist_id')->count(),
                'safety_flagged' => Patient::where('safety_flag', true)->count(),
                'by_compliance' => $this->countBy(Patient::query(), 'compliance_level', ['high', 'medium', 'low']),
            ],
            'therapists' => [
                'by_approval' => $this->countBy(Therapist::query(), 'approval_status', ApprovalStatus::values()),
                'at_capacity' => Therapist::where('approval_status', ApprovalStatus::APPROVED->value)
                    ->whereColumn('clients_count', '>=', 'clients_limit')->count(),
            ],
            'sessions' => [
                'today' => $this->countBy(TherapySession::whereDate('session_date', $today), 'status', SessionStatus::values()),
                'next_7_days' => TherapySession::whereBetween('session_date', [$today, $weekEnd])
                    ->whereIn('status', [SessionStatus::PENDING->value, SessionStatus::CONFIRMED->value])->count(),
                'awaiting_confirmation' => TherapySession::where('status', SessionStatus::PENDING->value)
                    ->where('session_date', '>=', $today)->count(),
                'reschedule_requests' => TherapySession::whereNotNull('reschedule_requested_at')->count(),
                'completed_last_30_days' => TherapySession::where('status', SessionStatus::COMPLETED->value)
                    ->where('session_date', '>=', now()->subDays(30)->toDateString())->count(),
            ],
            'clinical' => [
                'open_red_flags' => $this->countBy(RedFlag::where('status', 'open'), 'priority', ['high', 'medium', 'low']),
                'unassigned_red_flags' => RedFlag::where('status', 'open')->whereNull('assigned_to')->count(),
                'pending_therapist_switches' => TherapistSwitch::where('status', 'requested')->count(),
            ],
            'billing' => [
                'pending_payments' => Payment::where('status', PaymentReviewStatus::PENDING->value)->count(),
                'pending_payments_amount' => $this->money(Payment::where('status', PaymentReviewStatus::PENDING->value)->sum('amount')),
                'approved_last_30_days_amount' => $this->money(
                    Payment::where('status', PaymentReviewStatus::APPROVED->value)
                        ->where('reviewed_at', '>=', now()->subDays(30))->sum('amount')
                ),
                'active_subscriptions' => Subscription::where('verification_status', 'approved')
                    ->whereNull('cancelled_at')
                    ->whereDate('end_date', '>=', $today)->count(),
                'pending_withdrawals' => WalletWithdrawal::where('status', 'pending')->count(),
                'pending_withdrawals_amount' => $this->money(WalletWithdrawal::where('status', 'pending')->sum('amount')),
            ],
            'notifications_last_24h' => [
                'by_status' => $this->countBy(NotificationLog::where('created_at', '>=', now()->subDay()), 'status', NotificationLog::STATUSES),
                'whatsapp_failed' => NotificationLog::where('channel', NotificationLog::CHANNEL_WHATSAPP)
                    ->where('status', NotificationLog::STATUS_FAILED)
                    ->where('created_at', '>=', now()->subDay())->count(),
            ],
        ];
    }

    /**
     * Group counts keyed by column value, with every known value present
     * (zero when absent) so clients never have to null-check keys.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function countBy(Builder $query, string $column, array $keys): array
    {
        $counts = $query->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->mapWithKeys(fn ($count, $key) => [$key instanceof \BackedEnum ? $key->value : (string) $key => (int) $count]);

        return collect($keys)->mapWithKeys(fn (string $key) => [$key => $counts->get($key, 0)])->all();
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
