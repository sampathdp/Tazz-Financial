<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    public function index()
    {
        return Vendor::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $vendor = Vendor::create($data);

        return response()->json($vendor, 201);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $data = $this->validated($request, $vendor);
        $vendor->update($data);

        return $vendor;
    }

    public function destroy(Vendor $vendor)
    {
        if ($vendor->expenses()->exists()) {
            return response()->json(['message' => 'This vendor has expenses recorded against it and cannot be deleted.'], 422);
        }
        $vendor->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Vendor $vendor = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:24', Rule::unique('vendors', 'code')->ignore($vendor?->id)],
            'contact_person' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'tax_no' => 'nullable|string|max:32',
            'status' => [Rule::in(['Active', 'Inactive'])],
        ]);
    }
}
