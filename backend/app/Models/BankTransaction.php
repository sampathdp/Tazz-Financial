<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    protected $fillable = [
        'bank_account_id', 'txn_date', 'type', 'reference_no', 'description',
        'amount', 'balance_after', 'status', 'created_by',
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
