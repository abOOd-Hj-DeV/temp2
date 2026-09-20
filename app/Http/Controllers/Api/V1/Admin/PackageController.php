<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\Package\PackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function __construct(private PackageService $packages) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->packages->all()->map(fn (Package $p) => $this->packages->toArray($p, true)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:64|regex:/^[a-z0-9_\-]+$/',
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0|max:999999.99',
            'number_of_sessions' => 'required|integer|min:1|max:500',
            'duration_days' => 'required|integer|min:1|max:730',
            'daily_sessions_quota' => 'nullable|integer|min:1|max:10',
        ]);

        $package = $this->packages->create($request->user(), $data + ['daily_sessions_quota' => $data['daily_sessions_quota'] ?? 1]);

        return response()->json(['message' => 'Package created (unpublished).', 'package' => $this->packages->toArray($package, true)], 201);
    }

    public function update(Request $request, Package $package): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'description' => 'sometimes|nullable|string|max:2000',
            'price' => 'sometimes|numeric|min:0|max:999999.99',
            'number_of_sessions' => 'sometimes|integer|min:1|max:500',
            'duration_days' => 'sometimes|integer|min:1|max:730',
            'daily_sessions_quota' => 'sometimes|integer|min:1|max:10',
        ]);

        $package = $this->packages->update($request->user(), $package, $data);

        return response()->json(['message' => 'Package updated.', 'package' => $this->packages->toArray($package, true)]);
    }

    public function publish(Request $request, Package $package): JsonResponse
    {
        $package = $this->packages->setPublished($request->user(), $package, true);

        return response()->json(['message' => 'Package published.', 'package' => $this->packages->toArray($package, true)]);
    }

    public function unpublish(Request $request, Package $package): JsonResponse
    {
        $package = $this->packages->setPublished($request->user(), $package, false);

        return response()->json(['message' => 'Package unpublished.', 'package' => $this->packages->toArray($package, true)]);
    }
}
