<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAssetDisposal extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'disposal_date', 'proceeds', 'payment_method', 'cash_account_id', 'bank_account_id',
        'book_value', 'gain_loss', 'journal_entry_id', 'finance_transaction_id', 'notes', 'disposed_by',
    ];

    protected $casts = [
        'disposal_date' => 'date:Y-m-d',
        'proceeds' => 'decimal:2',
        'book_value' => 'decimal:2',
        'gain_loss' => 'decimal:2',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
