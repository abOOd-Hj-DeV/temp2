<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SupportType;
use App\Http\Controllers\Controller;
use App\Services\Support\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff triage of support tickets.
 */
class SupportTicketController extends Controller
{
    public function __construct(private SupportService $support) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'nullable|in:open,closed',
            'type' => ['nullable', Rule::in(SupportType::values())],
            'assigned_to' => 'nullable|uuid',
        ]);

        $tickets = $this->support->listAll(array_filter($filters, fn ($v) => $v !== null));

        return response()->json([
            'data' => $tickets->through(fn ($t) => $this->support->toArray($t)),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'data' => $this->support->toArray($this->support->show($request->user(), $id)),
        ]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['assigned_to' => 'required|uuid']);

        return response()->json([
            'data' => $this->support->toArray(
                $this->support->assign($request->user(), $id, $data['assigned_to'])
            ),
        ]);
    }

    public function setStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:open,closed']);

        return response()->json([
            'data' => $this->support->toArray(
                $this->support->setStatus($request->user(), $id, $data['status'])
            ),
        ]);
    }
}
