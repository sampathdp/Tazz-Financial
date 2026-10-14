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
    public const PURPOSE_FD = 'FixedDeposits';
    public const PURPOSE_SAVINGS = 'Savings';
    public const PURPOSE_PAWNING = 'Pawning';
    public const PURPOSE_EXPENSES = 'Expenses';
    /** Purposes that own exactly one primary till per branch (enforced by the purpose_key unique index). */
    public const DEDICATED_PURPOSES = [
        self::PURPOSE_COLLECTIONS, self::PURPOSE_DOCUMENT_CHARGES,
        self::PURPOSE_FD, self::PURPOSE_SAVINGS, self::PURPOSE_PAWNING, self::PURPOSE_EXPENSES,
    ];
    public const PURPOSES = [self::PURPOSE_GENERAL, ...self::DEDICATED_PURPOSES];
    /** Till name wording => purpose, used to classify tills created before purposes existed. */
    public const PURPOSE_NAME_PATTERNS = [
        '/\bfd\b|fixed deposit/i' => self::PURPOSE_FD,
        '/doc/i' => self::PURPOSE_DOCUMENT_CHARGES,
        '/collect/i' => self::PURPOSE_COLLECTIONS,
        '/saving/i' => self::PURPOSE_SAVINGS,
        '/pawn/i' => self::PURPOSE_PAWNING,
        '/expens/i' => self::PURPOSE_EXPENSES,
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
     * The branch's till for a kind of cash.
     *
     *  - A dedicated purpose (Collections, DocumentCharges, FixedDeposits, Savings, Pawning,
     *    Expenses) resolves to that purpose's active primary till and nothing else. If the branch has none
     *    this throws: posting the cash to some other till would hide the mistake in the books.
     *  - No purpose, or General, resolves to the branch's active General primary till (the oldest), or null.
     *
     * Floats, petty cash and in-transit accounts are never picked here. Shared by every *Poster so there is
     * one definition of "the branch's till".
     *
     * @throws \App\Exceptions\FinancePostingException when a dedicated till is missing
     */
    public static function branchPrimary(int $branchId, ?string $purpose = null): ?self
    {
        $primary = static::where('branch_id', $branchId)->where('status', 'Active')->whereIn('account_type', self::PRIMARY_TYPES);

        if ($purpose && $purpose !== self::PURPOSE_GENERAL) {
            $till = (clone $primary)->where('purpose', $purpose)->orderBy('id')->first();
            if (!$till && !config('finance.strict_till_purposes', true)) {
                // Legacy single-till mode (older test suite only): the branch's General till, else its oldest main till.
                return (clone $primary)->where('purpose', self::PURPOSE_GENERAL)->orderBy('id')->first() ?? (clone $primary)->orderBy('id')->first();
            }
            if (!$till) {
                $label = self::purposeLabel($purpose);
                $branch = optional(Branch::find($branchId))->name ?? "#{$branchId}";
                throw new \App\Exceptions\FinancePostingException(
                    "Branch {$branch} has no active {$label} cash account. Add one under Cashbook > Accounts (Used for: {$label}) first."
                );
            }

            return $till;
        }

        return $primary->where('purpose', self::PURPOSE_GENERAL)->orderBy('id')->first();
    }

    public static function purposeLabel(string $purpose): string
    {
        return [
            self::PURPOSE_COLLECTIONS => 'Collections',
            self::PURPOSE_DOCUMENT_CHARGES => 'Document Charges',
            self::PURPOSE_FD => 'Fixed Deposits',
            self::PURPOSE_SAVINGS => 'Savings',
            self::PURPOSE_PAWNING => 'Pawning',
            self::PURPOSE_EXPENSES => 'Expenses',
        ][$purpose] ?? $purpose;
    }

    /** The purpose a till name suggests, General when it says nothing. */
    public static function purposeFromName(string $name): string
    {
        foreach (self::PURPOSE_NAME_PATTERNS as $pattern => $purpose) {
            if (preg_match($pattern, $name)) {
                return $purpose;
            }
        }

        return self::PURPOSE_GENERAL;
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
