<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

/**
 * Branch isolation for a child record that has no branch_id of its own (a loan's collateral, a fund's
 * voucher, ...). Rows are visible only when their parent row is visible to the caller, so a route-bound
 * child fetched by bare id (`DELETE /loan-diary-notes/{note}`) 404s for a record of another branch instead
 * of relying on every controller to re-check the parent. The model declares
 * `protected static array $branchParents = ['fk_column' => Parent::class]`.
 */
trait ScopedThroughParent
{
    public static function bootScopedThroughParent(): void
    {
        static::addGlobalScope('parent_branch', function (Builder $builder) {
            $user = Auth::user();
            // All-branch roles see every branch anyway; unauthenticated work (jobs, console) is not user-scoped.
            if (!$user || ($user->role && $user->role->can_view_all_branches)) {
                return;
            }
            $table = $builder->getModel()->getTable();
            foreach (static::$branchParents as $column => $parent) {
                $visible = $parent::query()->withoutGlobalScope(SoftDeletingScope::class)->select('id');
                $builder->whereIn("{$table}.{$column}", $visible);
            }
        });
    }
}
