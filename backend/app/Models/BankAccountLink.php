<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bridges a chart_of_accounts leaf row to the bank_accounts row it represents — see the migration's docblock. */
class BankAccountLink extends Model
{
    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $fillable = ['account_id', 'bank_account_id'];

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }
}
