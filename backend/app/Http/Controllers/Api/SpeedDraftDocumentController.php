<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacilityDocument;
use App\Models\SpeedDraft;
use App\Support\DocStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpeedDraftDocumentController extends Controller
{
    public function index(SpeedDraft $speedDraft)
    {
        return $speedDraft->documents()->with(['category:id,name', 'uploader:id,name'])->get();
    }

    public function store(Request $request, SpeedDraft $speedDraft)
    {
        $data = $request->validate([
            'document_category_id' => ['required', 'exists:document_categories,id'],
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $path = DocStore::store($request->file('file'), "speed-drafts/{$speedDraft->id}/documents");

        $document = $speedDraft->documents()->create([
            'document_category_id' => $data['document_category_id'],
            'file_path' => $path,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($document->load('category:id,name'), 201);
    }

    public function show(SpeedDraft $speedDraft, FacilityDocument $document): StreamedResponse
    {
        abort_if($document->credit_facility_id !== $speedDraft->id, 404);
        abort_unless(DocStore::exists($document->file_path), 404);

        return DocStore::response($document->file_path);
    }

    public function destroy(SpeedDraft $speedDraft, FacilityDocument $document)
    {
        abort_if($document->credit_facility_id !== $speedDraft->id, 404);

        DocStore::delete($document->file_path);
        $document->delete();

        return response()->noContent();
    }
}
