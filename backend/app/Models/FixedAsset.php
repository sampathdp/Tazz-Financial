<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAsset extends Model
{
    use \App\Models\Concerns\BelongsToBranch;

    protected $fillable = [
        'code', 'name', 'asset_category_id', 'branch_id', 'description', 'serial_no', 'location',
        'custodian_staff_id', 'vendor_id', 'invoice_no', 'purchase_date', 'in_service_date',
        'cost', 'residual_value', 'useful_life_months', 'depreciation_method',
        'accumulated_depreciation', 'depreciated_through', 'status', 'acquisition_type',
        'payment_method', 'cash_account_id', 'bank_account_id', 'journal_entry_id', 'finance_transaction_id', 'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date:Y-m-d',
        'in_service_date' => 'date:Y-m-d',
        'depreciated_through' => 'date:Y-m-d',
        'cost' => 'decimal:2',
        'residual_value' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
    ];

    protected $appends = ['net_book_value'];

    public function getNetBookValueAttribute(): string
    {
        return bcsub((string) $this->cost, (string) $this->accumulated_depreciation, 2);
    }

    public function category()
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function custodian()
    {
        return $this->belongsTo(Staff::class, 'custodian_staff_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function depreciations()
    {
        return $this->hasMany(FixedAssetDepreciation::class)->orderBy('period');
    }

    public function disposal()
    {
        return $this->hasOne(FixedAssetDisposal::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
