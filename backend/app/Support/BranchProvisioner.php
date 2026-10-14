<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;

/**
 * Gives a branch the accounts every module needs the moment it exists, so a loan, deposit, pawn ticket or
 * collection can never fail (or, before, silently skip its ledger entry) for want of a till.
 *
 * Today that is one main till (General purpose) with its own ledger account under 1010 Cash in Hand. A General
 * till serves every purpose (collections, disbursements, FD, savings, pawning, document charges); dedicated
 * purpose tills can be added later in Cashbook > Accounts. Bank accounts are not created: they need real bank
 * details. The cross-branch in-transit account is created on first use.
 *
 * Idempotent: a branch that already has an active main till is left alone.
 */
class BranchProvisioner
{
    /** @return CashAccount|null the till created, or null when the branch already had one */
    public static function provision(Branch $branch): ?CashAccount
    {
        $has = CashAccount::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->whereIn('account_type', CashAccount::PRIMARY_TYPES)
            ->where('status', 'Active')
            ->exists();
        if ($has) {
            return null;
        }

        $till = CashAccount::withoutGlobalScopes()->create([
            // Unique by construction; replaced below once the id is known.
            'code' => 'NEW-' . \Illuminate\Support\Str::random(10),
            'branch_id' => $branch->id,
            'account_type' => $branch->is_head_office ? 'HeadOfficeMain' : 'BranchMain',
            'purpose' => CashAccount::PURPOSE_GENERAL,
            'name' => "{$branch->name} - Main Till",
            'opening_balance' => 0,
            'current_balance' => 0,
            'status' => 'Active',
        ]);
        $till->update(['code' => substr("{$branch->code}-CASH-{$till->id}", 0, 24)]);
        ChartOfAccount::forCashTill($till->fresh());

        return $till->fresh();
    }
}
