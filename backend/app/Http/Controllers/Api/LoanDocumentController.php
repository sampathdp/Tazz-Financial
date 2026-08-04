<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacilityDocument;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoanDocumentController extends Controller
{
    private const DOC_TYPES = ['ContractImage', 'Agreement', 'IdCopy', 'Other'];

    public function store(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'doc_type' => ['required', Rule::in(self::DOC_TYPES)],
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $path = $request->file('file')->store("loans/{$loan->id}/documents", 'public');

        $document = $loan->documents()->create([
            'doc_type' => $data['doc_type'],
            'file_path' => $path,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($document, 201);
    }

    public function show(Loan $loan, FacilityDocument $document): StreamedResponse
    {
        abort_if($document->credit_facility_id !== $loan->id, 404);
        abort_unless(Storage::disk('public')->exists($document->file_path), 404);

        return Storage::disk('public')->response($document->file_path, Str::afterLast($document->file_path, '/'));
    }

    public function destroy(Loan $loan, FacilityDocument $document)
    {
        abort_if($document->credit_facility_id !== $loan->id, 404);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return response()->noContent();
    }
}
