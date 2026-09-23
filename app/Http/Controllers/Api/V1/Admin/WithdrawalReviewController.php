<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletWithdrawal;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalReviewController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|in:pending,approved,rejected,paid']);

        $paginator = WalletWithdrawal::where('status', $request->input('status', WalletWithdrawal::STATUS_PENDING))
            ->orderBy('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($w) => $this->wallet->withdrawalToArray($w)),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function review(Request $request, WalletWithdrawal $withdrawal): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject,paid',
            'note' => 'nullable|string|max:1000',
        ]);

        $withdrawal = $this->wallet->review($withdrawal, $data['action'], $request->user(), $data['note'] ?? null);

        return response()->json(['message' => "Withdrawal {$withdrawal->status}.", 'data' => $this->wallet->withdrawalToArray($withdrawal)]);
    }
}
