<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pivot row for pawning_redemption_items — a composite-PK link table with no own attributes. */
class PawningRedemptionItem extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['pawning_redemption_id', 'facility_collateral_id'];
}
