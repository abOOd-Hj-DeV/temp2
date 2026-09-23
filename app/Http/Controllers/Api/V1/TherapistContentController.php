<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Therapist\TherapistContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TherapistContentController extends Controller
{
    public function __construct(private TherapistContentService $content) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['patient_id' => 'nullable|uuid']);

        $items = $this->content->library(
            $this->therapistOf($request),
            $this->perPage($request),
            $request->input('patient_id'),
        );

        return response()->json([
            'data' => $items->through(fn ($i) => $this->content->toArray($i)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $item = $this->content->create($this->therapistOf($request), $data);

        return response()->json(['content' => $this->content->toArray($item->load('patient'))], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $item = $this->content->show($this->therapistOf($request), $id);

        return response()->json(['content' => $this->content->toArray($item->load('patient'))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->rules(required: false));

        $item = $this->content->update($this->therapistOf($request), $id, $data);

        return response()->json(['content' => $this->content->toArray($item->load('patient'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->content->delete($this->therapistOf($request), $id);

        return response()->json(['message' => __('Content deleted.')]);
    }

    private function rules(bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'patient_id' => [$req, 'uuid'],
            'title' => [$req, 'string', 'max:200'],
            'content_type' => [$req, 'string', 'in:text,video,link'],
            'body' => ['nullable', 'string', 'max:20000', 'required_if:content_type,text'],
            'url' => ['nullable', 'url', 'max:2000', 'required_if:content_type,video,link'],
        ];
    }
}
