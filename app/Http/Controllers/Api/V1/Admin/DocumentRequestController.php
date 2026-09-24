<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequest;
use App\Services\Documents\DocumentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentRequestController extends Controller
{
    public function __construct(private DocumentRequestService $documents) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(DocumentRequest::STATUSES)],
            'user_id' => ['nullable', 'uuid'],
        ]);

        $paginator = $this->documents->listForStaff(
            $request->input('status'),
            $request->input('user_id'),
            $this->perPage($request),
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($d) => $this->documents->toArray($d, staffView: true)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'doc_type' => ['required', Rule::in(DocumentRequest::DOC_TYPES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $document = $this->documents->request($request->user(), $data);

        return response()->json([
            'message' => __('Document requested.'),
            'data' => $this->documents->toArray($document->load(['user', 'requester']), staffView: true),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'data' => $this->documents->toArray($this->documents->showForStaff($id), staffView: true),
        ]);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $document = $this->documents->review($request->user(), $id, $data['action'] === 'approve', $data['note'] ?? null);

        return response()->json([
            'message' => __("Document {$document->status}."),
            'data' => $this->documents->toArray($document->load(['user', 'requester', 'reviewer']), staffView: true),
        ]);
    }
}
