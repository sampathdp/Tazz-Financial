<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentVoucher extends Model
{
    protected $fillable = [
        'code', 'expense_id', 'vendor_id', 'amount', 'payment_method', 'paid_at', 'paid_by',
        'cash_account_id', 'bank_account_id', 'cheque_no', 'status', 'journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
