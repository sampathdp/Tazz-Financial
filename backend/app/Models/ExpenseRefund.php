<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseRefund extends Model
{
    protected $fillable = [
        'expense_id', 'branch_id', 'amount', 'forfeited_amount', 'payment_method', 'cash_account_id', 'bank_account_id',
        'reference', 'notes', 'refunded_at', 'received_by', 'finance_transaction_id', 'journal_entry_id',
    ];

    protected $casts = ['amount' => 'decimal:2', 'forfeited_amount' => 'decimal:2', 'refunded_at' => 'date'];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
