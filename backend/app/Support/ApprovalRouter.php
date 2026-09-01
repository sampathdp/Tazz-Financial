<?php

namespace App\Support;

use App\Models\ApprovalTier;
use App\Models\ApprovalType;

/**
 * Decides how many distinct approvals a request needs, by (code, branch,
 * amount). A branch-specific approval_tiers row wins over an all-branches
 * one; within that, the row whose [min_amount, max_amount] band contains the
 * amount. With no matching tier the fallback is
 * approval_types.required_approvals — so every flow that has no tiers
 * configured behaves exactly as it does today.
 */
class ApprovalRouter
{
    /**
     * @return array{required_approvals:int, tier_id:?int}
     */
    public static function route(string $code, ?int $branchId, float $amount): array
    {
        $type = ApprovalType::where('code', $code)->first();
        $fallback = $type?->required_approvals ?? 1;

        $tiers = ApprovalTier::whereHas('approvalType', fn ($q) => $q->where('code', $code))
            ->where('min_amount', '<=', $amount)
            ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->get();

        if ($tiers->isEmpty()) {
            return ['required_approvals' => (int) $fallback, 'tier_id' => null];
        }

        // Branch-specific beats all-branches; then the tightest band.
        $best = $tiers->sortBy([
            fn ($t) => $t->branch_id === null ? 1 : 0,
            fn ($t) => $t->max_amount === null ? PHP_FLOAT_MAX : (float) $t->max_amount,
        ])->first();

        return ['required_approvals' => (int) $best->required_approvals, 'tier_id' => $best->id];
    }
}
