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
 * Every model that holds a branch's data (loans, payments, cash and bank accounts, cashbook, petty
 * cash, ...) must `use` this trait, so isolation covers lists AND route-model-bound single records
 * (a record of another branch simply 404s) without each controller re-implementing it. A model whose
 * rows may legitimately have no branch can set `public static $sharedWhenNoBranch = true`.
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
            $column = $builder->getModel()->getTable() . '.branch_id';
            if (property_exists($builder->getModel(), 'sharedWhenNoBranch') && $builder->getModel()::$sharedWhenNoBranch) {
                // A company-level row (no branch, e.g. a head-office bank account) stays visible to every branch.
                $builder->where(fn ($q) => $q->where($column, $user->branch_id)->orWhereNull($column));

                return;
            }
            $builder->where($column, $user->branch_id);
        });
    }
}
