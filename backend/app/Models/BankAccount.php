<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $fillable = [
        'branch_id', 'chart_of_account_id', 'bank_id', 'name', 'bank_branch_name', 'account_number',
        'account_type', 'currency', 'opening_balance', 'current_balance', 'status', 'notes',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function transactions()
    {
        return $this->hasMany(BankTransaction::class)->orderByDesc('txn_date')->orderByDesc('id');
    }

    public function reconciliations()
    {
        return $this->hasMany(BankReconciliation::class)->orderByDesc('statement_date');
    }

    /** The chart_of_accounts leaf row this bank account posts to in the general ledger. */
    public function chartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class);
    }
}
