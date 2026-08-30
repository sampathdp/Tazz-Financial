<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityCollateralDocument extends Model
{
    protected $fillable = ['facility_collateral_id', 'file_path', 'uploaded_by'];

    // Some hosts' PDO returns integer columns as strings — cast the FKs so the
    // ownership guards in the collateral-document controllers (strict !==) never misfire.
    protected $casts = [
        'facility_collateral_id' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function collateral()
    {
        return $this->belongsTo(FacilityCollateral::class, 'facility_collateral_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
