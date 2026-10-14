<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;

/**
 * Gives a branch the tills every module needs the moment it exists, so a loan, deposit, pawn ticket or
 * collection can never fail for want of a till.
 *
 * A branch gets one main till for each purpose: General plus every dedicated purpose (collections, document
 * charges, disbursements, FD, savings, pawning, expenses), each with its own ledger account under 1010 Cash in
 * Hand. CashAccount::branchPrimary() never falls back from a dedicated purpose to another till, so a branch
 * missing one gets a clear error instead of a wrong posting. Bank accounts are not created: they need real bank
 * details. The cross-branch in-transit account is created on first use.
 *
 * Idempotent: a purpose the branch already has a till for is left alone.
 */
class BranchProvisioner
{
    /** @return list<CashAccount> the tills created (empty when the branch already had them all) */
    public static function provision(Branch $branch): array
    {
        $created = [];
        $hasGeneral = CashAccount::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->whereIn('account_type', CashAccount::PRIMARY_TYPES)
            ->where('purpose', CashAccount::PURPOSE_GENERAL)
            ->exists();
        if (!$hasGeneral) {
            $created[] = self::createTill($branch, CashAccount::PURPOSE_GENERAL);
        }
        foreach (CashAccount::DEDICATED_PURPOSES as $purpose) {
            // Any status counts: an inactive till still owns the purpose (the unique index), so it is
            // reactivated by the user rather than duplicated here.
            $has = CashAccount::withoutGlobalScopes()
                ->where('branch_id', $branch->id)
                ->whereIn('account_type', CashAccount::PRIMARY_TYPES)
                ->where('purpose', $purpose)
                ->exists();
            if (!$has) {
                $created[] = self::createTill($branch, $purpose);
            }
        }

        return $created;
    }

    private static function createTill(Branch $branch, string $purpose): CashAccount
    {
        $label = $purpose === CashAccount::PURPOSE_GENERAL ? 'Main Till' : CashAccount::purposeLabel($purpose) . ' Till';
        $till = CashAccount::withoutGlobalScopes()->create([
            // Unique by construction; replaced below once the id is known.
            'code' => 'NEW-' . \Illuminate\Support\Str::random(10),
            'branch_id' => $branch->id,
            'account_type' => $branch->is_head_office ? 'HeadOfficeMain' : 'BranchMain',
            'purpose' => $purpose,
            'name' => "{$branch->name} - {$label}",
            'opening_balance' => 0,
            'current_balance' => 0,
            'status' => 'Active',
        ]);
        $till->update(['code' => substr("{$branch->code}-CASH-{$till->id}", 0, 24)]);
        ChartOfAccount::forCashTill($till->fresh());

        return $till->fresh();
    }
}
