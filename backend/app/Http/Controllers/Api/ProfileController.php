<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Support\DocStore;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $staff = $this->ownStaff($request);

        return $staff ? $this->present($staff) : response()->json(null);
    }

    /** Self-service edit of the caller's own personal details. Code, branch, designation and
     *  status stay admin-managed through the Staff screen. */
    public function updateStaff(Request $request)
    {
        $staff = $this->ownStaff($request);
        abort_unless($staff, 404, 'No staff record is linked to your account.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'nic_no' => ['nullable', 'string', 'max:20', 'regex:/^(\d{9}[VvXx]|\d{12})$/', Rule::unique('staff', 'nic_no')->ignore($staff->id)],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);
        if (!empty($data['nic_no'])) {
            $data['nic_no'] = strtoupper(trim($data['nic_no']));
        }
        $staff->update($data);

        return $this->present($staff->fresh('branch:id,name,code'));
    }

    private function ownStaff(Request $request): ?Staff
    {
        $user = $request->user();

        return Staff::with('branch:id,name,code')->where('user_id', $user->id)->first()
            ?? Staff::with('branch:id,name,code')->whereNull('user_id')->where('email', $user->email)->first();
    }

    private function present(Staff $staff): array
    {
        return $staff->only(['id', 'staff_code', 'name', 'nic_no', 'phone', 'email', 'designation', 'branch_id', 'status', 'created_at'])
            + ['branch' => $staff->branch];
    }
}
