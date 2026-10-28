<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetCategory extends Model
{
    protected $fillable = [
        'name', 'asset_account_id', 'accumulated_depreciation_account_id', 'expense_account_id',
        'default_useful_life_months', 'default_residual_percent', 'status',
    ];

    protected $casts = ['default_residual_percent' => 'decimal:2'];

    public function assetAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'accumulated_depreciation_account_id');
    }

    public function expenseAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function assets()
    {
        return $this->hasMany(FixedAsset::class);
    }
}
