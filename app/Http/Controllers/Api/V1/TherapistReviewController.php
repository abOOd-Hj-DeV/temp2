<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Therapist\TherapistReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TherapistReviewController extends Controller
{
    public function __construct(private TherapistReviewService $reviews) {}

    /** Anonymous reviews of a therapist, visible to any verified user. */
    public function index(Request $request, string $id): JsonResponse
    {
        return response()->json($this->reviews->forTherapist($id, $this->perPage($request)));
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->rules());

        $review = $this->reviews->create($this->patientOf($request), $id, $data);

        return response()->json(['review' => $this->reviews->toArray($review)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->rules(required: false));

        $review = $this->reviews->update($this->patientOf($request), $id, $data);

        return response()->json(['review' => $this->reviews->toArray($review)]);
    }

    /** The authenticated patient's own review of this therapist, if any. */
    public function mine(Request $request, string $id): JsonResponse
    {
        $review = $this->reviews->mine($this->patientOf($request), $id);

        return response()->json(['review' => $review ? $this->reviews->toArray($review) : null]);
    }

    /** A therapist reading reviews left for them. */
    public function own(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reviews->ownReviews($this->therapistOf($request), $this->perPage($request)),
        ]);
    }

    private function rules(bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'rating' => [$req, 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
