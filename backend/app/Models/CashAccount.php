<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    protected $fillable = ['branch_id', 'name', 'opening_balance', 'current_balance', 'status'];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function entries()
    {
        return $this->hasMany(CashbookEntry::class)->orderByDesc('entry_date')->orderByDesc('id');
    }

    public function reconciliations()
    {
        return $this->hasMany(CashReconciliation::class)->orderByDesc('reconciliation_date');
    }

    public function transfersOut()
    {
        return $this->hasMany(CashTransfer::class, 'from_cash_account_id');
    }

    public function transfersIn()
    {
        return $this->hasMany(CashTransfer::class, 'to_cash_account_id');
    }
}
