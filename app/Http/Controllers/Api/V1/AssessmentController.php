<?php
// app/Http/Controllers/Api/V1/AssessmentController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AssessmentController extends Controller
{
    public function __construct(private AssessmentService $assessmentService) {}

    /**
     * إنشاء تقييم جديد
     *
     * @authenticated
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'required|in:phq9,gad7',
                'answers' => 'required|array',
                'answers.*' => 'required|integer|min:0|max:3'
            ]);

            $user = $request->user();

            Log::info('New assessment requested', [
                'user_id' => $user->id,
                'type' => $validated['type']
            ]);

            $result = $this->assessmentService->createAssessment($user, $validated);

            return response()->json([
                'message' => 'Assessment completed successfully',
                'assessment' => $result['assessment'],
                'interpretation' => $result['interpretation'],
                'recommendations' => $result['recommendations'],
                'red_flag_created' => $result['red_flag_created'],
                'notification_sent' => $result['notification_sent']
            ], 201);

        } catch (ValidationException $e) {
            Log::warning('Assessment validation failed', [
                'user_id' => $request->user()?->id,
                'errors' => $e->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to create assessment', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to complete assessment. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * الحصول على سجل التقييمات
     *
     * @authenticated
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:50',
                'type' => 'nullable|in:phq9,gad7'
            ]);

            $user = $request->user();

            Log::info('Assessment history requested', [
                'user_id' => $user->id,
                'filters' => $validated
            ]);

            $history = $this->assessmentService->getAssessmentHistory($user, $validated);

            if (!$history['has_profile']) {
                return response()->json([
                    'message' => $history['message'],
                    'has_profile' => false
                ], 200);
            }

            return response()->json([
                'message' => 'Assessment history retrieved successfully',
                'assessments' => $history['assessments'],
                'pagination' => $history['pagination'],
                'statistics' => $history['statistics']
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve assessment history', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to load assessment history',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
