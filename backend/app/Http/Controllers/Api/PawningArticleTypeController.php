<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PawningArticleType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PawningArticleTypeController extends Controller
{
    public function index()
    {
        return PawningArticleType::orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $type = PawningArticleType::create($data);

        return response()->json($type, 201);
    }

    public function update(Request $request, PawningArticleType $pawningArticleType)
    {
        $data = $this->validated($request, $pawningArticleType);
        $pawningArticleType->update($data);

        return $pawningArticleType;
    }

    public function destroy(PawningArticleType $pawningArticleType)
    {
        $pawningArticleType->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?PawningArticleType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('pawning_article_types', 'name')->ignore($type?->id)],
            'code' => ['required', 'string', 'max:16', Rule::unique('pawning_article_types', 'code')->ignore($type?->id)],
            'status' => [Rule::in(['Active', 'Inactive'])],
            'sort_order' => 'nullable|integer',
        ]);
    }
}
