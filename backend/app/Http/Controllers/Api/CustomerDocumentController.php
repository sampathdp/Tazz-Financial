<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerDocumentController extends Controller
{
    private const DOC_TYPES = ['NIC', 'Passport', 'DrivingLicense', 'UtilityBill', 'Photo', 'Other'];

    public function store(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'doc_type' => ['required', Rule::in(self::DOC_TYPES)],
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $path = $request->file('file')->store("customers/{$customer->id}/documents", 'public');

        $document = $customer->documents()->create([
            'doc_type' => $data['doc_type'],
            'file_path' => $path,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($document, 201);
    }

    public function show(Customer $customer, CustomerDocument $document): StreamedResponse
    {
        abort_if((int) $document->customer_id !== (int) $customer->id, 404);
        abort_unless(Storage::disk('public')->exists($document->file_path), 404);

        return Storage::disk('public')->response($document->file_path, Str::afterLast($document->file_path, '/'));
    }

    public function destroy(Customer $customer, CustomerDocument $document)
    {
        abort_if($document->customer_id !== $customer->id, 404);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return response()->noContent();
    }
}
