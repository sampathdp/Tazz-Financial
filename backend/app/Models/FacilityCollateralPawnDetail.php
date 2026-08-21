<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityCollateralPawnDetail extends Model
{
    protected $primaryKey = 'facility_collateral_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'facility_collateral_id', 'item_no', 'article_type_id', 'declared_karat', 'tested_karat',
        'gross_weight_grams', 'net_weight_grams', 'rate_per_gram', 'appraised_value', 'max_loan_value',
        'item_status', 'appraised_by', 'remark',
    ];

    protected $casts = [
        'item_no' => 'integer',
        'declared_karat' => 'decimal:2',
        'tested_karat' => 'decimal:2',
        'gross_weight_grams' => 'decimal:3',
        'net_weight_grams' => 'decimal:3',
        'rate_per_gram' => 'decimal:2',
        'appraised_value' => 'decimal:2',
        'max_loan_value' => 'decimal:2',
    ];

    public function collateral()
    {
        return $this->belongsTo(FacilityCollateral::class, 'facility_collateral_id');
    }

    public function articleType()
    {
        return $this->belongsTo(PawningArticleType::class, 'article_type_id');
    }

    public function appraisedBy()
    {
        return $this->belongsTo(User::class, 'appraised_by');
    }
}
