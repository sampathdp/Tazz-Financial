<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacilityCollateral;
use App\Models\FacilityCollateralDocument;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoanCollateralDocumentController extends Controller
{
    public function store(Request $request, Loan $loan, FacilityCollateral $collateral)
    {
        abort_if($collateral->credit_facility_id !== $loan->id, 404);

        $data = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $path = $request->file('file')->store("loans/{$loan->id}/collateral/{$collateral->id}/documents", 'public');

        $document = $collateral->documents()->create([
            'file_path' => $path,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($document, 201);
    }

    public function show(Loan $loan, FacilityCollateral $collateral, FacilityCollateralDocument $document): StreamedResponse
    {
        abort_if($collateral->credit_facility_id !== $loan->id, 404);
        abort_if($document->facility_collateral_id !== $collateral->id, 404);
        abort_unless(Storage::disk('public')->exists($document->file_path), 404);

        return Storage::disk('public')->response($document->file_path, Str::afterLast($document->file_path, '/'));
    }

    public function destroy(Loan $loan, FacilityCollateral $collateral, FacilityCollateralDocument $document)
    {
        abort_if($collateral->credit_facility_id !== $loan->id, 404);
        abort_if($document->facility_collateral_id !== $collateral->id, 404);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return response()->noContent();
    }
}
