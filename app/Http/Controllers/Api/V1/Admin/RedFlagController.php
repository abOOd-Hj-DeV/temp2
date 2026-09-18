<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RedFlagType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RedFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Clinical staff triage of red flags raised by assessments / mood logs.
 */
class RedFlagController extends Controller
{
    public function __construct(
        private RedFlagService $redFlags,
        private AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(RedFlagType::values())],
            'priority' => 'nullable|in:high,medium,low',
            'assigned_to' => 'nullable|uuid',
            'from_date' => 'nullable|date',
        ]);

        return response()->json([
            'data' => $this->redFlags->getOpenRedFlags(array_filter($filters, fn ($v) => $v !== null)),
            'stats' => $this->redFlags->getStats(array_filter([
                'type' => $filters['type'] ?? null,
                'from_date' => $filters['from_date'] ?? null,
            ])),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $flag = $this->redFlags->find($id) ?? throw new NotFoundHttpException('Red flag not found.');

        return response()->json(['data' => $this->redFlags->toArray($flag)]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|uuid']);

        $flag = $this->redFlags->find($id) ?? throw new NotFoundHttpException('Red flag not found.');

        $assignee = User::whereKey($data['user_id'])
            ->whereIn('role', [UserRole::ADMIN->value, UserRole::SUPER_ADMIN->value, UserRole::THERAPIST->value])
            ->where('is_active', true)
            ->first();

        if (! $assignee) {
            throw ValidationException::withMessages(['user_id' => 'Assignee must be an active staff member or therapist.']);
        }

        $this->redFlags->assignTo($flag->id, $assignee->id);
        $this->audit->record($request->user(), AuditLogService::RED_FLAG_ASSIGNED, $flag->id, ['assigned_to' => $assignee->id]);

        return response()->json(['message' => 'Red flag assigned.', 'data' => $this->redFlags->toArray($flag->refresh())]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:open,resolved',
            'action_taken' => 'required_if:status,resolved|nullable|string|min:5|max:2000',
        ]);

        $flag = $this->redFlags->find($id) ?? throw new NotFoundHttpException('Red flag not found.');

        $this->redFlags->updateStatus($flag->id, $data['status'], $data['action_taken'] ?? null);
        $this->audit->record($request->user(), AuditLogService::RED_FLAG_UPDATED, $flag->id, [
            'status' => $data['status'],
        ]);

        return response()->json(['message' => 'Red flag updated.', 'data' => $this->redFlags->toArray($flag->refresh())]);
    }
}
