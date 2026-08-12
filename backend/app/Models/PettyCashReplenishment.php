<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PettyCashReplenishment extends Model
{
    protected $fillable = [
        'code', 'petty_cash_fund_id', 'amount', 'funding_method', 'cash_account_id', 'bank_account_id',
        'paid_at', 'paid_by', 'status', 'void_reason', 'voided_by', 'voided_at', 'journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function fund()
    {
        return $this->belongsTo(PettyCashFund::class, 'petty_cash_fund_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
