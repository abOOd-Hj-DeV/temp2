<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only access to the audit trail. Querying it is itself audited.
 */
class AuditLogController extends Controller
{
    public function __construct(private AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => 'nullable|uuid',
            'action' => ['nullable', Rule::in(AuditLogService::actions())],
            'entity_id' => 'nullable|uuid',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $query = AuditLog::query()
            ->with('user:id,name,role')
            ->orderByDesc('timestamp')
            ->orderByDesc('id');

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('timestamp', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('timestamp', '<=', $filters['to']);
        }

        $paginator = $query->paginate($this->perPage($request, 25));

        $this->audit->record($request->user(), AuditLogService::AUDIT_LOG_QUERIED, null, array_filter($filters));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'entity_id' => $log->entity_id,
                'details' => $log->details,
                'timestamp' => $log->timestamp?->toISOString(),
                'actor' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'role' => $log->user->role?->value,
                ] : ['id' => $log->user_id, 'name' => null, 'role' => null],
            ])->values(),
            'actions' => AuditLogService::actions(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
