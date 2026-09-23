<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;

/** Public, read-only FAQ listing for the app's support screen. */
class FaqController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Faq::where('is_published', true)
                ->orderBy('sort_order')->orderBy('created_at')
                ->get(['id', 'question', 'answer', 'sort_order']),
        ]);
    }
}
