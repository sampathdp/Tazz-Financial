<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashbookEntry extends Model
{
    use \App\Models\Concerns\BelongsToBranch;

    protected $fillable = [
        'branch_id', 'cash_account_id', 'journal_entry_id', 'entry_date', 'direction', 'category', 'amount',
        'balance_after', 'source_type', 'source_id', 'description', 'created_by', 'approved_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'entry_date' => 'date',
    ];

    public const DIRECTIONS = ['In', 'Out'];
    public const CATEGORIES = ['Loan Collection', 'Expense', 'Transfer', 'Deposit', 'Other'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
