<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedDraftType extends Model
{
    protected $fillable = [
        'name', 'code', 'category', 'reference_format',
        'interest_rate_percent', 'rate_period',
        'min_amount', 'max_amount', 'min_period_months', 'max_period_months',
        'grace_period_days', 'default_charge_rate_percent', 'requires_collateral',
        'sort_order', 'status',
    ];

    protected $casts = [
        'interest_rate_percent' => 'decimal:3',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'min_period_months' => 'integer',
        'max_period_months' => 'integer',
        'grace_period_days' => 'integer',
        'default_charge_rate_percent' => 'decimal:3',
        'requires_collateral' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** speed_draft_type_id lives on speed_draft_facility_details, not
     *  credit_facilities — see SpeedDraft's docblock. */
    public function facilityDetails()
    {
        return $this->hasMany(SpeedDraftFacilityDetail::class);
    }
}
