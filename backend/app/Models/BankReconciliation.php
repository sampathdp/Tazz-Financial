<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    use Concerns\ScopedThroughParent;

    /** Visible only through a parent the caller's branch can see. */
    protected static array $branchParents = ['bank_account_id' => BankAccount::class];

    protected $fillable = [
        'bank_account_id', 'statement_date', 'statement_balance', 'book_balance',
        'difference', 'status', 'notes', 'reconciled_by', 'reconciled_at',
    ];

    protected $casts = [
        'statement_balance' => 'decimal:2',
        'book_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'statement_date' => 'date',
        'reconciled_at' => 'datetime',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function reconciler()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
