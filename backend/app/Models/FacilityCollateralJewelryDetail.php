<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityCollateralJewelryDetail extends Model
{
    protected $primaryKey = 'facility_collateral_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['facility_collateral_id', 'weight_grams', 'purity', 'appraised_value'];

    protected $casts = [
        'weight_grams' => 'decimal:3',
        'appraised_value' => 'decimal:2',
    ];

    public function collateral()
    {
        return $this->belongsTo(FacilityCollateral::class, 'facility_collateral_id');
    }
}
