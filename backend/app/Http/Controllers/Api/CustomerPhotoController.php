<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DocStore;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer's profile photo — a single image stored on customers.photo_path,
 * distinct from the KYC files in CustomerDocumentController (which are a
 * one-to-many list). Kept behind auth like the documents route: an <img src>
 * can't send the token, so the frontend fetches it as a blob.
 */
class CustomerPhotoController extends Controller
{
    public function show(Customer $customer): StreamedResponse
    {
        abort_unless($customer->photo_path && DocStore::exists($customer->photo_path), 404);

        return DocStore::response($customer->photo_path);
    }

    public function store(Request $request, Customer $customer)
    {
        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        // Save the new file and point the row at it first; only then remove the old file, so a failure
        // part-way never leaves the customer with a dangling photo_path and no image.
        $old = $customer->photo_path;
        $path = DocStore::store($request->file('photo'), "customers/{$customer->id}/photo");
        $customer->update(['photo_path' => $path]);
        DocStore::delete($old);

        return response()->json(['has_photo' => true], 201);
    }

    public function destroy(Customer $customer)
    {
        if ($customer->photo_path) {
            $old = $customer->photo_path;
            $customer->update(['photo_path' => null]);
            DocStore::delete($old);
        }

        return response()->noContent();
    }
}
