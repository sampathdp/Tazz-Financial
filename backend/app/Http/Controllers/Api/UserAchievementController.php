<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAchievement;
use Illuminate\Http\Request;

/** The signed-in user's own achievements (profile page). Every query is scoped to the caller. */
class UserAchievementController extends Controller
{
    public function index(Request $request)
    {
        return UserAchievement::where('user_id', $request->user()->id)
            ->orderByRaw('achieved_on IS NULL')
            ->orderByDesc('achieved_on')
            ->orderByDesc('id')
            ->get();
    }

    public function store(Request $request)
    {
        $achievement = UserAchievement::create($this->validated($request) + ['user_id' => $request->user()->id]);

        return response()->json($achievement, 201);
    }

    public function update(Request $request, UserAchievement $achievement)
    {
        $this->authorizeOwner($request, $achievement);
        $achievement->update($this->validated($request));

        return $achievement;
    }

    public function destroy(Request $request, UserAchievement $achievement)
    {
        $this->authorizeOwner($request, $achievement);
        $achievement->delete();

        return response()->noContent();
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
