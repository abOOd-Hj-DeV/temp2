<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminOverviewService;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __construct(private AdminOverviewService $overview) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->overview->overview()]);
    }
}
