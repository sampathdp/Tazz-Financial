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
        $absPath = storage_path('app/public/'.$document->file_path);
        \Log::info('doc.show', [
            'customer_id_url' => $customer->id,
            'document_id' => $document->id,
            'customer_id_row' => $document->customer_id,
            'parent_matches' => $document->customer_id === $customer->id,
            'file_path' => $document->file_path,
            'disk_root' => config('filesystems.disks.public.root'),
            'storage_exists' => Storage::disk('public')->exists($document->file_path),
            'php_file_exists' => file_exists($absPath),
            'is_readable' => @is_readable($absPath),
            'abs_path' => $absPath,
        ]);

        abort_if($document->customer_id !== $customer->id, 404);
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
