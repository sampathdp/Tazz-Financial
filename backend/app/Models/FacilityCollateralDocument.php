<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityCollateralDocument extends Model
{
    protected $fillable = ['facility_collateral_id', 'file_path', 'uploaded_by'];

    public function collateral()
    {
        return $this->belongsTo(FacilityCollateral::class, 'facility_collateral_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
