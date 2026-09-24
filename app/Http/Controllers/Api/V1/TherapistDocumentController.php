<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A therapist's view of the documents staff asked them to provide. */
class TherapistDocumentController extends Controller
{
    public function __construct(private DocumentRequestService $documents) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->documents->listForOwner($request->user(), $this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($d) => $this->documents->toArray($d)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'data' => $this->documents->toArray($this->documents->showForOwner($request->user(), $id)),
        ]);
    }

    public function upload(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ]);

        $document = $this->documents->upload($request->user(), $id, $request->file('file'));

        return response()->json([
            'message' => __('Document submitted for review.'),
            'data' => $this->documents->toArray($document),
        ]);
    }
}
