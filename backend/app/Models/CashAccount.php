<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    use \App\Models\Concerns\BelongsToBranch;

    /** Branch/head-office tills that receive auto-posted collections and are the default source for outbound cash at a branch. */
    public const PRIMARY_TYPES = ['BranchMain', 'HeadOfficeMain'];

    /** What a till is for. A module asks for its purpose (branchPrimary($branch, CashAccount::PURPOSE_FD)). */
    public const PURPOSE_GENERAL = 'General';
    public const PURPOSE_COLLECTIONS = 'Collections';
    public const PURPOSE_DOCUMENT_CHARGES = 'DocumentCharges';
    public const PURPOSE_DISBURSEMENTS = 'Disbursements';
    public const PURPOSE_FD = 'FixedDeposits';
    public const PURPOSE_SAVINGS = 'Savings';
    public const PURPOSE_PAWNING = 'Pawning';
    public const PURPOSES = [
        self::PURPOSE_GENERAL, self::PURPOSE_COLLECTIONS, self::PURPOSE_DOCUMENT_CHARGES, self::PURPOSE_DISBURSEMENTS,
        self::PURPOSE_FD, self::PURPOSE_SAVINGS, self::PURPOSE_PAWNING,
    ];

    /** Every account_type — see the add_finance_metadata_to_cash_accounts migration. */
    public const ACCOUNT_TYPES = [
        'HeadOfficeMain', 'BranchMain', 'PettyCash',
        'CashierFloat', 'CollectionCash', 'InTransit', 'Other',
    ];

    protected $fillable = [
        'code', 'branch_id', 'account_type', 'purpose', 'responsible_user_id', 'parent_cash_account_id',
        'chart_of_account_id', 'name', 'opening_balance', 'current_balance',
        'allow_negative', 'last_reconciled_at', 'status',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'allow_negative' => 'boolean',
        'last_reconciled_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** The person accountable for this account's cash — a float holder or petty-cash custodian. Null for branch/HO tills. */
    public function responsibleUser()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** A float's home account — where it was issued from and settles back to. */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_cash_account_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_cash_account_id');
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

    /**
     * The branch's primary till for auto-posted collections and default
     * outbound cash — its oldest active BranchMain/HeadOfficeMain account.
     * Falls back to the oldest active account of any type so a branch that
     * pre-dates the Finance-metadata backfill (every row typed) still
     * resolves. A CashierFloat / PettyCash / InTransit account is never
     * picked here. Shared by every *Poster so there's one definition of
     * "the branch's till".
     */
    public static function branchPrimary(int $branchId, ?string $purpose = null): ?self
    {
        $base = static::where('branch_id', $branchId)->where('status', 'Active');

        // 1. A till the branch dedicated to this kind of cash.
        if ($purpose && $purpose !== self::PURPOSE_GENERAL) {
            $dedicated = (clone $base)->where('purpose', $purpose)->whereIn('account_type', self::PRIMARY_TYPES)->orderBy('id')->first();
            if ($dedicated) {
                return $dedicated;
            }
        }

        // 2. The branch's general till - one that is not reserved for some other purpose, so a
        //    dedicated "FD" or "Doc charge" till is never silently used for unrelated cash.
        $general = fn ($q) => $q->where(fn ($w) => $w->whereNull('purpose')->orWhere('purpose', self::PURPOSE_GENERAL));
        $primary = (clone $base)->whereIn('account_type', self::PRIMARY_TYPES);
        $fallback = $general(clone $primary)->orderBy('id')->first()
            // 3. Every primary till is dedicated to something: the lowest-id one (previous behaviour).
            ?? (clone $primary)->orderBy('id')->first();

        return $fallback ?? $base->orderBy('id')->first();
    }

    /**
     * The branch's cash-in-transit holding account, created on first use. A
     * cross-branch transfer's money sits here between "released" by the
     * sending branch and "received" by the destination — see
     * FinanceRequestService::release()/receive().
     */
    public static function branchInTransit(int $branchId): self
    {
        $existing = static::where('branch_id', $branchId)->where('account_type', 'InTransit')->first();
        if ($existing) {
            return $existing;
        }
        $branchCode = optional(Branch::find($branchId))->code ?? $branchId;

        return static::create([
            'code' => "{$branchCode}-TRANSIT",
            'branch_id' => $branchId,
            'account_type' => 'InTransit',
            'name' => "Cash in Transit - {$branchCode}",
            'opening_balance' => 0,
            'current_balance' => 0,
            'status' => 'Active',
        ]);
    }
}
