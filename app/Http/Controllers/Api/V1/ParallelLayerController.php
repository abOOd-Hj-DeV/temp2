<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Therapist\ParallelLayerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParallelLayerController extends Controller
{
    public function __construct(private ParallelLayerService $layers) {}

    public function show(Request $request, string $patientId): JsonResponse
    {
        $layer = $this->layers->get($this->therapistOf($request), $patientId);

        return response()->json(['layer' => $layer ? $this->layers->toArray($layer) : null]);
    }

    public function store(Request $request, string $patientId): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'array', 'max:50']]);

        $layer = $this->layers->save($this->therapistOf($request), $patientId, $data['content']);

        return response()->json(['layer' => $this->layers->toArray($layer)]);
    }
}
