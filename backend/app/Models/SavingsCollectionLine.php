<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingsCollectionLine extends Model
{
    protected $fillable = [
        'savings_collection_sheet_id', 'savings_account_id', 'amount', 'collected',
        'reference_no', 'savings_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'collected' => 'boolean',
    ];

    public function sheet()
    {
        return $this->belongsTo(SavingsCollectionSheet::class, 'savings_collection_sheet_id');
    }

    public function savingsAccount()
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    public function savingsTransaction()
    {
        return $this->belongsTo(SavingsTransaction::class);
    }
}
