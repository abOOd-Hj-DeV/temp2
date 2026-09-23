<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Program;
use App\Services\Program\ProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProgramController extends Controller
{
    public function __construct(private ProgramService $programs) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->programs->all()->map(fn (Program $p) => $this->programs->toArray($p))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'required|string|max:5000',
            'is_core' => 'nullable|boolean',
        ]);

        $program = $this->programs->create($request->user(), $data + ['is_core' => (bool) ($data['is_core'] ?? false)]);

        return response()->json(['message' => 'Program created.', 'program' => $this->programs->toArray($program)], 201);
    }

    public function update(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'description' => 'sometimes|string|max:5000',
            'is_core' => 'sometimes|boolean',
        ]);

        $program = $this->programs->update($request->user(), $program, $data);

        return response()->json(['message' => 'Program updated.', 'program' => $this->programs->toArray($program)]);
    }

    public function destroy(Request $request, Program $program): JsonResponse
    {
        $this->programs->delete($request->user(), $program);

        return response()->json(['message' => 'Program deleted.']);
    }

    public function storeModule(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate($this->moduleRules(true));

        $module = $this->programs->addModule($request->user(), $program, $data);

        return response()->json(['message' => 'Module created.', 'module' => $this->programs->moduleToArray($module)], 201);
    }

    public function updateModule(Request $request, Program $program, Module $module): JsonResponse
    {
        $this->assertBelongs($program, $module);

        $data = $request->validate($this->moduleRules(false));

        $module = $this->programs->updateModule($request->user(), $module, $data);

        return response()->json(['message' => 'Module updated.', 'module' => $this->programs->moduleToArray($module)]);
    }

    public function destroyModule(Request $request, Program $program, Module $module): JsonResponse
    {
        $this->assertBelongs($program, $module);

        $this->programs->deleteModule($request->user(), $module);

        return response()->json(['message' => 'Module deleted.']);
    }

    /** @return array<string, string> */
    private function moduleRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => "{$required}|string|max:160",
            'description' => "{$required}|string|max:10000",
            'content_type' => "{$required}|in:text,video",
            'exercise' => 'sometimes|nullable|string|max:10000',
            'tracking_tools' => 'sometimes|nullable|array|max:20',
            'tracking_tools.*' => 'string|max:80',
            'order' => 'sometimes|integer|min:0|max:1000',
        ];
    }

    private function assertBelongs(Program $program, Module $module): void
    {
        if ($module->program_id !== $program->id) {
            throw new NotFoundHttpException('Module not found.');
        }
    }
}
