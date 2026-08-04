<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    protected $fillable = ['branch_id', 'chart_of_account_id', 'name', 'opening_balance', 'current_balance', 'status'];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function entries()
    {
        return $this->hasMany(CashbookEntry::class)->orderByDesc('entry_date')->orderByDesc('id');
    }

    public function reconciliations()
    {
        return $this->hasMany(CashReconciliation::class)->orderByDesc('as_at_date');
    }

    public function transfersOut()
    {
        return $this->hasMany(CashTransfer::class, 'from_cash_account_id');
    }

    public function transfersIn()
    {
        return $this->hasMany(CashTransfer::class, 'to_cash_account_id');
    }

    /** The chart_of_accounts leaf row this till posts to in the general ledger. */
    public function chartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    /** The branch's primary till for auto-posted collections — its oldest active cash account. Branches expected to keep exactly one; if several exist, the first one created wins. Shared by CashbookPoster and LoanLedgerPoster so there's one definition of "the branch's till", not two. */
    public static function branchPrimary(int $branchId): ?self
    {
        return static::where('branch_id', $branchId)->where('status', 'Active')->orderBy('id')->first();
    }
}
