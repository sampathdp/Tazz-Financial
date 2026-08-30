<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityCollateral extends Model
{
    protected $table = 'facility_collateral';

    protected $fillable = ['credit_facility_id', 'collateral_type', 'collateral_category', 'description', 'estimated_value'];

    protected $casts = [
        'estimated_value' => 'decimal:2',
        // Some hosts' PDO returns integer columns as strings — keep the FK an int
        // so the collateral-document controllers' strict !== guards never misfire.
        'credit_facility_id' => 'integer',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    /** Populated only when collateral_type = Vehicle. */
    public function vehicleDetail()
    {
        return $this->hasOne(FacilityCollateralVehicleDetail::class);
    }

    /** Populated only when collateral_type = Jewelry. */
    public function jewelryDetail()
    {
        return $this->hasOne(FacilityCollateralJewelryDetail::class);
    }

    /** Populated only when collateral_type = PawnedJewelry. */
    public function pawnDetail()
    {
        return $this->hasOne(FacilityCollateralPawnDetail::class);
    }

    public function documents()
    {
        return $this->hasMany(FacilityCollateralDocument::class);
    }
}
