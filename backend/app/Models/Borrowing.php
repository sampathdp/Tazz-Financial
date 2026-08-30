<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Borrowing extends Model
{
    protected $fillable = [
        'borrowing_number', 'branch_id', 'type', 'lender_name', 'source', 'purpose',
        'principal_amount', 'outstanding_amount', 'interest_rate_percent', 'borrowing_date',
        'receipt_method', 'receipt_cash_account_id', 'receipt_bank_account_id', 'bank_account_id',
        'reference_no', 'notes', 'status', 'created_by',
    ];

    protected $casts = [
        'borrowing_date' => 'date:Y-m-d',
        'principal_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'interest_rate_percent' => 'decimal:3',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function receiptCashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'receipt_cash_account_id');
    }

    public function receiptBankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'receipt_bank_account_id');
    }

    public function transactions()
    {
        return $this->hasMany(BorrowingTransaction::class)->orderByDesc('id');
    }

    public function repledgedItems()
    {
        return $this->hasMany(BorrowingRepledgedItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
