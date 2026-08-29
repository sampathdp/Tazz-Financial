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
    public function store(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'document_category_id' => ['required', 'exists:document_categories,id'],
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $path = $request->file('file')->store("loans/{$loan->id}/documents", 'public');

        $document = $loan->documents()->create([
            'document_category_id' => $data['document_category_id'],
            'file_path' => $path,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($document, 201);
    }

    public function show(Loan $loan, FacilityDocument $document): StreamedResponse
    {
        $absPath = storage_path('app/public/'.$document->file_path);
        \Log::info('doc.show', [
            'loan_id' => $loan->id,
            'document_id' => $document->id,
            'credit_facility_id' => $document->credit_facility_id,
            'parent_matches' => $document->credit_facility_id === $loan->id,
            'file_path' => $document->file_path,
            'disk_root' => config('filesystems.disks.public.root'),
            'storage_exists' => Storage::disk('public')->exists($document->file_path),
            'php_file_exists' => file_exists($absPath),
            'is_readable' => @is_readable($absPath),
            'abs_path' => $absPath,
        ]);

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
