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
                    $builder->whereIn($builder->getModel()->getTable() . '.branch_id', \App\Support\CurrentBranch::withCenters($filter));
                }

                return;
            }
            // Branch-scoped users are always forced to their own branch — never trust client input.
            $column = $builder->getModel()->getTable() . '.branch_id';
            // A branch sees its own rows and those of the Centers that report up to it.
            $ids = $user->branch_id ? \App\Support\CurrentBranch::withCenters((int) $user->branch_id) : [];
            if (property_exists($builder->getModel(), 'sharedWhenNoBranch') && $builder->getModel()::$sharedWhenNoBranch) {
                // A company-level row (no branch, e.g. a head-office bank account) stays visible to every branch.
                $builder->where(fn ($q) => $q->whereIn($column, $ids)->orWhereNull($column));

                return;
            }
            $builder->whereIn($column, $ids);
        });

        // Write isolation: a branch-confined user can only create rows in their own branch. A missing
        // branch_id is filled in; one naming another branch is refused (never trust client input).
        static::creating(function ($model) {
            $user = Auth::user();
            if (!$user || !$user->branch_id || ($user->role && $user->role->can_view_all_branches)) {
                return;
            }
            $requested = $model->getAttribute('branch_id');
            if ($requested === null || $requested === '') {
                $model->setAttribute('branch_id', $user->branch_id);

                return;
            }
            if (!in_array((int) $requested, \App\Support\CurrentBranch::withCenters((int) $user->branch_id), true)) {
                abort(403, 'You can only create records for your own branch.');
            }
        });
    }
}
