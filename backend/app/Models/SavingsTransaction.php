<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingsTransaction extends Model
{
    protected $fillable = [
        'txn_number', 'savings_account_id', 'txn_date', 'type', 'direction', 'amount', 'running_balance',
        'payment_method', 'bank_id', 'bank_account_id', 'reference_no', 'collection_sheet_id', 'interest_run_id',
        'finance_transaction_id', 'notes', 'status', 'void_of_id', 'created_by',
    ];

    protected $casts = [
        'txn_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
    ];

    public function savingsAccount()
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function collectionSheet()
    {
        return $this->belongsTo(SavingsCollectionSheet::class);
    }

    public function interestRun()
    {
        return $this->belongsTo(SavingsInterestRun::class);
    }

    public function financeTransaction()
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
