<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    protected $fillable = [
        'bank_account_id', 'journal_entry_id', 'txn_date', 'transaction_type', 'reference_no', 'description',
        'amount', 'balance_after', 'status', 'created_by',
        'contra_kind', 'contra_cash_account_id', 'contra_bank_account_id', 'contra_chart_of_account_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'txn_date' => 'date',
    ];

    /** Deposits and incoming transfers increase the account balance; everything else decreases it. */
    public const CREDIT_TYPES = ['Deposit', 'TransferIn'];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contraCashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'contra_cash_account_id');
    }

    public function contraBankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'contra_bank_account_id');
    }

    public function contraChartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'contra_chart_of_account_id');
    }
}
