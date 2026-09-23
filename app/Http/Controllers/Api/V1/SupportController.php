<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SupportType;
use App\Http\Controllers\Controller;
use App\Services\Support\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Patient-side support tickets (الدعم والمساعدة screen).
 */
class SupportController extends Controller
{
    public function __construct(private SupportService $support) {}

    public function index(Request $request): JsonResponse
    {
        $tickets = $this->support->mine($request->user());

        return response()->json([
            'data' => $tickets->through(fn ($t) => $this->support->toArray($t)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(SupportType::values())],
            'subject' => 'nullable|string|max:120',
            'description' => 'required|string|max:2000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $ticket = $this->support->create(
            $request->user(), $data, $request->file('attachment'),
        );

        return response()->json(['data' => $this->support->toArray($ticket)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'data' => $this->support->toArray($this->support->show($request->user(), $id)),
        ]);
    }
}
