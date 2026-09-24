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
        $request->validate([
            'patient_id' => 'nullable|uuid',
            'search' => 'nullable|string|max:100',
            'content_type' => 'nullable|string|in:text,video,link',
        ]);

        $items = $this->content->library(
            $this->therapistOf($request),
            $this->perPage($request),
            $request->input('patient_id'),
            $request->input('search'),
            $request->input('content_type'),
        );

        return response()->json([
            'data' => $items->through(fn ($i) => $this->content->toArray($i)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $item = $this->content->create($this->therapistOf($request), $data);

        return response()->json(['content' => $this->content->toArray($item->load('assignedPatients:user_id,full_name'))], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $item = $this->content->show($this->therapistOf($request), $id);

        return response()->json(['content' => $this->content->toArray($item->load('assignedPatients:user_id,full_name'))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->rules(required: false));

        $item = $this->content->update($this->therapistOf($request), $id, $data);

        return response()->json(['content' => $this->content->toArray($item->load('assignedPatients:user_id,full_name'))]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'patient_ids' => ['required', 'array', 'min:1', 'max:50'],
            'patient_ids.*' => ['uuid', 'distinct'],
        ]);

        $item = $this->content->assign($this->therapistOf($request), $id, $data['patient_ids']);

        return response()->json(['content' => $this->content->toArray($item->load('assignedPatients:user_id,full_name'))]);
    }

    public function unassign(Request $request, string $id, string $patientId): JsonResponse
    {
        $item = $this->content->unassign($this->therapistOf($request), $id, $patientId);

        return response()->json(['content' => $this->content->toArray($item->load('assignedPatients:user_id,full_name'))]);
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
            'patient_id' => ['nullable', 'uuid'],
            'patient_ids' => ['nullable', 'array', 'max:50'],
            'patient_ids.*' => ['uuid', 'distinct'],
            'title' => [$req, 'string', 'max:200'],
            'content_type' => [$req, 'string', 'in:text,video,link'],
            'body' => ['nullable', 'string', 'max:20000', 'required_if:content_type,text'],
            'url' => ['nullable', 'url', 'max:2000', 'required_if:content_type,video,link'],
        ];
    }
}
