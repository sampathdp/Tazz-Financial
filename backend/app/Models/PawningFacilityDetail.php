<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PawningFacilityDetail extends Model
{
    protected $primaryKey = 'credit_facility_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'pawning_type_id', 'applied_rate_percent', 'is_special_rate',
        'total_gross_weight_grams', 'total_net_weight_grams', 'total_appraised_value',
        'repay_due_date', 'renewal_count', 'previous_facility_id', 'purpose',
    ];

    protected $casts = [
        'applied_rate_percent' => 'decimal:3',
        'is_special_rate' => 'boolean',
        'total_gross_weight_grams' => 'decimal:3',
        'total_net_weight_grams' => 'decimal:3',
        'total_appraised_value' => 'decimal:2',
        'repay_due_date' => 'date',
        'renewal_count' => 'integer',
    ];

    public function pawning()
    {
        return $this->belongsTo(Pawning::class, 'credit_facility_id');
    }

    public function pawningType()
    {
        return $this->belongsTo(PawningType::class);
    }

    public function previousFacility()
    {
        return $this->belongsTo(Pawning::class, 'previous_facility_id');
    }
}
