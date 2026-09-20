<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Assessment\StoreAssessmentRequest;
use App\Services\Assessment\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function __construct(
        private AssessmentService $assessments,
    ) {}

    public function store(StoreAssessmentRequest $request): JsonResponse
    {
        return response()->json(
            $this->assessments->createAssessment($request->user(), $request->validated()),
            201
        );
    }

    public function history(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request, 10);

        return response()->json(
            $this->assessments->getHistory($request->user(), $perPage)
        );
    }
}
