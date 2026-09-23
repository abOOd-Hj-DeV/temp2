<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TherapistSwitch;
use App\Services\Therapist\TherapistSwitchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TherapistSwitchReviewController extends Controller
{
    public function __construct(private TherapistSwitchService $switches) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|in:requested,approved,rejected']);

        $paginator = TherapistSwitch::where('status', $request->input('status', 'requested'))
            ->orderBy('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($s) => $this->switches->toArray($s)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function review(Request $request, TherapistSwitch $switch): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:1000',
        ]);

        $switch = $this->switches->decide($switch, $data['action'] === 'approve', $request->user(), $data['note'] ?? null);

        return response()->json(['message' => "Switch request {$switch->status}.", 'data' => $this->switches->toArray($switch)]);
    }
}
