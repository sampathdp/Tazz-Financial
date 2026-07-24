<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index()
    {
        return Branch::with('parent:id,name,code')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $branch = Branch::create($data + ['status' => $data['status'] ?? 'Active', 'type' => $data['type'] ?? 'Branch']);

        return response()->json($branch->load('parent:id,name,code'), 201);
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $this->validated($request, $branch);
        if (isset($data['parent_id']) && $data['parent_id'] === $branch->id) {
            return response()->json(['message' => 'A branch cannot be its own parent.'], 422);
        }
        $branch->update($data);

        return $branch->load('parent:id,name,code');
    }

    public function destroy(Branch $branch)
    {
        if ($branch->is_head_office) {
            return response()->json(['message' => 'The head office branch cannot be deleted.'], 422);
        }
        if ($branch->users()->exists()) {
            return response()->json(['message' => 'This branch has users assigned and cannot be deleted.'], 422);
        }
        if ($branch->centers()->exists()) {
            return response()->json(['message' => 'This branch has centers under it and cannot be deleted. Delete or move them first.'], 422);
        }
        $branch->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        $type = $request->input('type', $branch?->type ?? 'Branch');

        $data = $request->validate([
            'name' => 'required|string|max:128',
            'code' => ['required', 'string', 'max:16', Rule::unique('branches', 'code')->ignore($branch?->id)],
            'type' => [Rule::in(['Branch', 'Center'])],
            'parent_id' => [
                $type === 'Center' ? 'required' : 'nullable',
                'exists:branches,id',
                Rule::notIn([$branch?->id]),
            ],
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:190',
            'is_head_office' => 'boolean',
            'status' => [Rule::in(['Active', 'Inactive'])],
        ]);

        if (!empty($data['parent_id'])) {
            $parent = Branch::find($data['parent_id']);
            if (!$parent || $parent->type !== 'Branch') {
                return $this->fail('A center must be linked to a Branch, not another Center.');
            }
        }
        if (($data['type'] ?? $type) !== 'Center') {
            $data['parent_id'] = null;
        }

        return $data;
    }

    private function fail(string $message): never
    {
        abort(response()->json(['message' => $message], 422));
    }
}
