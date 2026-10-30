<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Support\DocStore;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function photo(Request $request)
    {
        $path = $request->user()->photo_path;
        abort_unless($path && DocStore::exists($path), 404);

        return DocStore::response($path);
    }

    public function storePhoto(Request $request)
    {
        $request->validate(['photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120']);

        $user = $request->user();
        $old = $user->photo_path;
        $path = DocStore::store($request->file('photo'), "users/{$user->id}/photo");
        $user->forceFill(['photo_path' => $path])->save();
        DocStore::delete($old);

        return response()->json(['has_photo' => true], 201);
    }

    public function destroyPhoto(Request $request)
    {
        $user = $request->user();
        if ($user->photo_path) {
            $old = $user->photo_path;
            $user->forceFill(['photo_path' => null])->save();
            DocStore::delete($old);
        }

        return response()->noContent();
    }

    /** The signed-in user's staff record (linked by user_id, falling back to email), or null. */
    public function staff(Request $request)
    {
        $user = $request->user();
        $staff = Staff::with('branch:id,name,code')->where('user_id', $user->id)->first()
            ?? Staff::with('branch:id,name,code')->whereNull('user_id')->where('email', $user->email)->first();

        if (!$staff) {
            return response()->json(null);
        }

        return $staff->only(['id', 'staff_code', 'name', 'nic_no', 'phone', 'email', 'designation', 'branch_id', 'status', 'created_at'])
            + ['branch' => $staff->branch];
    }
}
