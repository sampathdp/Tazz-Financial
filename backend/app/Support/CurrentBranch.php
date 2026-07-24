<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Resolves the branch a request should be scoped to. Only meaningful for
 * users whose role can see all branches — branch-scoped users are always
 * forced to their own branch_id server-side (see BelongsToBranch) and never
 * consult this class's request-param path.
 */
class CurrentBranch
{
    /**
     * The branch id an all-branches user wants to filter to, from
     * ?branch_id=, or null to mean "all branches".
     */
    public static function filterId(): ?int
    {
        $requested = Request::query('branch_id');

        return $requested !== null ? (int) $requested : null;
    }

    /**
     * The branch id that should be written onto a new record created by the
     * current user: their own branch, or (for all-branch users) the explicit
     * ?branch_id= filter if one was given.
     */
    public static function forWrite(): ?int
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }
        if ($user->role && $user->role->can_view_all_branches) {
            return self::filterId();
        }

        return $user->branch_id;
    }
}
