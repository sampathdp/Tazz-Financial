<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAchievement;
use Illuminate\Http\Request;

/**
 * The signed-in user's own achievements (profile page). Owner actions are scoped to the caller;
 * every create/edit goes back to Pending until an admin approves it (approve/reject are admin-only
 * routes, surfaced in the Approvals inbox).
 */
class UserAchievementController extends Controller
{
    public function index(Request $request)
    {
        return UserAchievement::with('reviewer:id,name')
            ->where('user_id', $request->user()->id)
            ->orderByRaw('achieved_on IS NULL')
            ->orderByDesc('achieved_on')
            ->orderByDesc('id')
            ->get();
    }

    public function store(Request $request)
    {
        $achievement = UserAchievement::create($this->validated($request) + [
            'user_id' => $request->user()->id,
            'status' => UserAchievement::PENDING,
        ]);

        return response()->json($achievement, 201);
    }

    public function update(Request $request, UserAchievement $achievement)
    {
        $this->authorizeOwner($request, $achievement);
        $achievement->update($this->validated($request) + [
            'status' => UserAchievement::PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
        ]);

        return $achievement;
    }

    public function destroy(Request $request, UserAchievement $achievement)
    {
        $this->authorizeOwner($request, $achievement);
        $achievement->delete();

        return response()->noContent();
    }

    public function approve(Request $request, UserAchievement $achievement)
    {
        return $this->review($request, $achievement, UserAchievement::APPROVED, null);
    }

    public function reject(Request $request, UserAchievement $achievement)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->review($request, $achievement, UserAchievement::REJECTED, $data['reason'] ?? null);
    }

    private function review(Request $request, UserAchievement $achievement, string $status, ?string $note)
    {
        abort_if($achievement->user_id === $request->user()->id, 403, 'You cannot approve your own achievement.');
        abort_unless($achievement->status === UserAchievement::PENDING, 422, 'This achievement has already been reviewed.');

        $achievement->update([
            'status' => $status,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        return $achievement;
    }

    private function authorizeOwner(Request $request, UserAchievement $achievement): void
    {
        abort_unless($achievement->user_id === $request->user()->id, 403, 'You can only change your own achievements.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'achieved_on' => 'nullable|date|before_or_equal:today',
        ]);
    }
}
