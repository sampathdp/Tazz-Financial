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
    /** @var array<int, int[]> branch id => [itself, its centers], memoised for the request */
    private static array $visible = [];

    /** A branch and the Centers that report up to it: a branch sees its own centers' data. */
    public static function withCenters(int $branchId): array
    {
        return self::$visible[$branchId] ??= array_merge([$branchId], \App\Models\Branch::where('parent_id', $branchId)->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    public static function flushCache(): void
    {
        self::$visible = [];
    }

    /**
     * The branch ids a ledger report should be limited to, or null for every branch. A branch-confined user
     * is always limited to their own branch (and its centers); an all-branches user may pick one with ?branch_id=.
     *
     * @return int[]|null
     */
    public static function reportBranchIds(): ?array
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }
        if ($user->role && $user->role->can_view_all_branches) {
            $filter = self::filterId();

            return $filter ? self::withCenters($filter) : null;
        }

        return $user->branch_id ? self::withCenters((int) $user->branch_id) : [];
    }

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

    /**
     * Same isolation rule as BelongsToBranch's global scope, but callable
     * directly on any query builder (Eloquent or DB::table) — for report
     * queries whose models don't carry that trait (Expense, CashbookEntry,
     * FixedDeposit, ...) or that go through DB::table()/joins entirely,
     * where a global scope can't reach. $column may be table-qualified
     * (e.g. 'cf.branch_id') for joined queries.
     */
    public static function applyScope($query, string $column = 'branch_id')
    {
        $user = Auth::user();
        if (!$user) {
            return $query;
        }
        if ($user->role && $user->role->can_view_all_branches) {
            $filter = self::filterId();
            if ($filter) {
                $query->whereIn($column, self::withCenters($filter));
            }

            return $query;
        }

        return $query->whereIn($column, $user->branch_id ? self::withCenters((int) $user->branch_id) : []);
    }
}
