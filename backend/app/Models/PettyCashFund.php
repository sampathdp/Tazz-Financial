<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PettyCashFund extends Model
{
    use \App\Models\Concerns\BelongsToBranch;

    protected $fillable = [
        'code', 'branch_id', 'custodian_id', 'chart_of_account_id', 'name',
        'float_limit', 'current_balance', 'status',
    ];

    protected $casts = [
        'float_limit' => 'decimal:2',
        'current_balance' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function custodian()
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    /** The chart_of_accounts leaf row this float posts to in the general ledger. */
    public function chartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function replenishments()
    {
        return $this->hasMany(PettyCashReplenishment::class)->orderByDesc('paid_at')->orderByDesc('id');
    }

    public function vouchers()
    {
        return $this->hasMany(PettyCashVoucher::class)->orderByDesc('spent_at')->orderByDesc('id');
    }
}
