<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        abort_unless($customer->photo_path && Storage::disk('public')->exists($customer->photo_path), 404);

        return Storage::disk('public')->response($customer->photo_path, Str::afterLast($customer->photo_path, '/'));
    }

    public function store(Request $request, Customer $customer)
    {
        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        if ($customer->photo_path) {
            Storage::disk('public')->delete($customer->photo_path);
        }

        $path = $request->file('photo')->store("customers/{$customer->id}/photo", 'public');
        $customer->update(['photo_path' => $path]);

        return response()->json(['has_photo' => true], 201);
    }

    public function destroy(Customer $customer)
    {
        if ($customer->photo_path) {
            Storage::disk('public')->delete($customer->photo_path);
            $customer->update(['photo_path' => null]);
        }

        return response()->noContent();
    }
}
