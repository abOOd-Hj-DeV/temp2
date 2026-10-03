<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RedFlagType;
use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\Files\AccountFileFence;
use App\Services\RedFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $flag = DB::transaction(function () use ($request, $flag, $data) {
            AccountFileFence::lock([$flag->patient_id]);
            $flag->refresh();
            $assignee = $this->redFlags->resolveAssignee($flag, $data['user_id']);

            if (! $assignee) {
                throw ValidationException::withMessages([
                    'user_id' => 'Assignee must be active clinical staff or an approved therapist treating this patient.',
                ]);
            }

            $flag = $this->redFlags->reassign($flag, $assignee);
            $this->audit->record($request->user(), AuditLogService::RED_FLAG_ASSIGNED, $flag->id, ['assigned_to' => $assignee->id]);

            return $flag;
        });

        return response()->json(['message' => 'Red flag assigned.', 'data' => $this->redFlags->toArray($flag)]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:open,resolved',
            'action_taken' => 'required_if:status,resolved|nullable|string|min:5|max:2000',
        ]);

        $flag = $this->redFlags->find($id) ?? throw new NotFoundHttpException('Red flag not found.');

        DB::transaction(function () use ($request, $flag, $data) {
            AccountFileFence::lock([$flag->patient_id]);
            $this->redFlags->updateStatus($flag->id, $data['status'], $data['action_taken'] ?? null);
            $this->audit->record($request->user(), AuditLogService::RED_FLAG_UPDATED, $flag->id, [
                'status' => $data['status'],
            ]);
        });

        return response()->json(['message' => 'Red flag updated.', 'data' => $this->redFlags->toArray($flag->refresh())]);
    }
}
