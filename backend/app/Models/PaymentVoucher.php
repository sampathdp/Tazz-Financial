<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentVoucher extends Model
{
    /** The branch comes from the parent record (see the add_branch_id_to_money_child_tables migration). */
    protected static function booted(): void
    {
        static::creating(function ($m) {
            if (empty($m->branch_id) && $m->expense_id) {
                $m->branch_id = \App\Models\Expense::withoutGlobalScopes()->whereKey($m->expense_id)->value('branch_id');
            }
        });
    }

    protected $fillable = [
        'code', 'expense_id', 'vendor_id', 'amount', 'payment_method', 'paid_at', 'paid_by',
        'cash_account_id', 'bank_account_id', 'cheque_no', 'status', 'journal_entry_id', 'finance_transaction_id',
        'void_reason', 'voided_by', 'voided_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
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

    public function financeTransaction()
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
