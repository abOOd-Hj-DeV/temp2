<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Staff CRUD for FAQ entries (content_manager / admin / super_admin). */
class FaqManagementController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Faq::orderBy('sort_order')->orderBy('created_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $faq = Faq::create(['id' => (string) Str::uuid()] + $data);

        return response()->json(['faq' => $faq], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $faq = $this->findOrFail($id);
        $faq->update($request->validate($this->rules(required: false)));

        return response()->json(['faq' => $faq->refresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->findOrFail($id)->delete();

        return response()->json(['message' => __('FAQ deleted.')]);
    }

    private function findOrFail(string $id): Faq
    {
        return Faq::whereKey($id)->first() ?? throw new NotFoundHttpException('FAQ not found.');
    }

    private function rules(bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'question' => [$req, 'string', 'max:500'],
            'answer' => [$req, 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
