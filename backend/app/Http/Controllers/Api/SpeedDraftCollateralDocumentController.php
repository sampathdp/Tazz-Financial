<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DocStore;
use App\Models\FacilityCollateral;
use App\Models\FacilityCollateralDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Mirrors LoanCollateralDocumentController — see that class for the shared conventions. */
class SpeedDraftCollateralDocumentController extends Controller
{
    public function store(Request $request, FacilityCollateral $collateral)
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $path = DocStore::store($request->file('file'), "speed-drafts/{$collateral->credit_facility_id}/collateral/{$collateral->id}/documents");

        $document = $collateral->documents()->create([
            'file_path' => $path,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($document, 201);
    }

    public function show(FacilityCollateral $collateral, FacilityCollateralDocument $document): StreamedResponse
    {
        abort_if($document->facility_collateral_id !== $collateral->id, 404);
        abort_unless(DocStore::exists($document->file_path), 404);

        return DocStore::response($document->file_path);
    }

    public function destroy(FacilityCollateral $collateral, FacilityCollateralDocument $document)
    {
        abort_if($document->facility_collateral_id !== $collateral->id, 404);

        DocStore::delete($document->file_path);
        $document->delete();

        return response()->noContent();
    }
}
