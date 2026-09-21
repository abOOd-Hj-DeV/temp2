<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Delivery ledger for outbound notifications (what was sent to whom, over
 * which channel, and whether it arrived). Bodies are never stored.
 */
class NotificationLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => 'nullable|uuid',
            'channel' => ['nullable', Rule::in(NotificationLog::CHANNELS)],
            'status' => ['nullable', Rule::in(NotificationLog::STATUSES)],
            'event' => 'nullable|string|max:80',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $query = NotificationLog::query();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $summary = (clone $query)
            ->select('channel', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('channel', 'status')
            ->get()
            ->groupBy('channel')
            ->map(fn ($rows) => $rows->pluck('aggregate', 'status')->map(fn ($n) => (int) $n));

        $paginator = $query->with('user:id,name,role')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (NotificationLog $log) => [
                'id' => $log->id,
                'channel' => $log->channel,
                'event' => $log->event,
                'event_key' => $log->event_key,
                'status' => $log->status,
                'attempts' => $log->attempts,
                'error' => $log->error,
                'context' => $log->context,
                'recipient' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'role' => $log->user->role?->value,
                ] : null,
                'sent_at' => $log->sent_at?->toISOString(),
                'created_at' => $log->created_at?->toISOString(),
            ])->values(),
            'summary' => collect(NotificationLog::CHANNELS)->mapWithKeys(fn (string $channel) => [
                $channel => collect(NotificationLog::STATUSES)->mapWithKeys(fn (string $status) => [
                    $status => (int) ($summary->get($channel)?->get($status) ?? 0),
                ])->all(),
            ])->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
