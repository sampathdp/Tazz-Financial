<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyProfileController extends Controller
{
    public function show()
    {
        return CompanyProfile::current();
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'tagline' => 'nullable|string|max:191',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:191',
            'tax_no' => 'nullable|string|max:64',
            'currency' => 'nullable|string|max:32',
        ]);
        $profile = CompanyProfile::current();
        $profile->update($data);

        return $profile;
    }

    /** Public, pre-auth — the Login screen and every page's <title> read this before a token exists. */
    public function branding()
    {
        $profile = CompanyProfile::current();

        return [
            'name' => $profile->name,
            'tagline' => $profile->tagline,
            'hasLogo' => (bool) $profile->logo_path,
        ];
    }

    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => 'required|file|mimes:jpg,jpeg,png,webp|max:2048']);
        $profile = CompanyProfile::current();
        if ($profile->logo_path) {
            Storage::disk('public')->delete($profile->logo_path);
        }
        $path = $request->file('logo')->store('company', 'public');
        $profile->update(['logo_path' => $path]);

        return ['hasLogo' => true];
    }

    /**
     * Public, pre-auth — a plain <img src> can't carry an Authorization
     * header, so this can't sit behind auth:sanctum the way
     * LoanDocumentController::show does. Always the same URL (see api.ts's
     * `logoUrl`); the frontend cache-busts with its own ?v= query param.
     */
    public function logo(): StreamedResponse
    {
        $profile = CompanyProfile::current();
        abort_unless($profile->logo_path && Storage::disk('public')->exists($profile->logo_path), 404);

        return Storage::disk('public')->response($profile->logo_path, Str::afterLast($profile->logo_path, '/'));
    }
}
