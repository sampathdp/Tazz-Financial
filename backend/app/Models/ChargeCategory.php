<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChargeCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'chart_of_account_id', 'status', 'sort_order'];

    /** The chart_of_accounts income account this category's charges post to (e.g. a dedicated Service Charge account) — see LoanLedgerPoster::splitLines(). */
    public function chartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class);
    }
}
