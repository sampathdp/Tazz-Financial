<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentCategory;
use Illuminate\Http\Request;

class DocumentCategoryController extends Controller
{
    public function index()
    {
        return DocumentCategory::orderBy('sort_order')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:document_categories',
            'status' => 'required|in:Active,Inactive',
            'sort_order' => 'integer'
        ]);

        $category = DocumentCategory::create($data);
        return response()->json($category, 201);
    }

    public function update(Request $request, DocumentCategory $documentCategory)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:document_categories,name,' . $documentCategory->id,
            'status' => 'required|in:Active,Inactive',
            'sort_order' => 'integer'
        ]);

        $documentCategory->update($data);
        return response()->json($documentCategory);
    }

    public function destroy(DocumentCategory $documentCategory)
    {
        // Don't delete if it's in use
        if (\App\Models\FacilityDocument::where('document_category_id', $documentCategory->id)->exists()) {
            return response()->json(['message' => 'Cannot delete document category because it is in use.'], 409);
        }

        $documentCategory->delete();
        return response()->noContent();
    }
}
