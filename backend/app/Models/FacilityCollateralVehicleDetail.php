<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityCollateralVehicleDetail extends Model
{
    protected $primaryKey = 'facility_collateral_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'facility_collateral_id', 'vehicle_type', 'make_model', 'registration_no', 'chassis_no', 'engine_no', 'year_of_manufacture',
    ];

    protected $casts = [
        'year_of_manufacture' => 'integer',
    ];

    public function collateral()
    {
        return $this->belongsTo(FacilityCollateral::class, 'facility_collateral_id');
    }
}
