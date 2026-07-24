<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Applies branch-level data isolation to a model with a `branch_id` column.
 * Users whose role is confined to their own branch (role.can_view_all_branches
 * = false) only ever see rows for their own branch; all-branch roles (admin,
 * head office, auditors, ...) see everything unless CurrentBranch::filter()
 * narrows it via an explicit ?branch_id= request param.
 *
 * No business-data model uses this yet (none exist), but every future
 * branch-scoped model (Loan, PawnTicket, CashbookEntry, ...) should `use`
 * this trait to get isolation for free rather than re-implementing scoping.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            $user = Auth::user();
            if (!$user) {
                return;
            }
            if ($user->role && $user->role->can_view_all_branches) {
                $filter = \App\Support\CurrentBranch::filterId();
                if ($filter) {
                    $builder->where($builder->getModel()->getTable() . '.branch_id', $filter);
                }

                return;
            }
            // Branch-scoped users are always forced to their own branch — never trust client input.
            $builder->where($builder->getModel()->getTable() . '.branch_id', $user->branch_id);
        });
    }
}
