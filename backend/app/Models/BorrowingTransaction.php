<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BorrowingTransaction extends Model
{
    protected $fillable = [
        'borrowing_id', 'voucher_no', 'kind', 'amount', 'txn_date', 'payment_method',
        'cash_account_id', 'bank_account_id', 'reference_no', 'notes',
        'outstanding_before', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'txn_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'outstanding_before' => 'decimal:2',
    ];

    public function borrowing()
    {
        return $this->belongsTo(Borrowing::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
