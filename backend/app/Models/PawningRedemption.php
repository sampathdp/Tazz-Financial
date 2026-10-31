<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PawningRedemption extends Model
{
    use \App\Models\Concerns\HasVoidedRows;

    // No created_at/updated_at columns on this table — settled_at is the domain timestamp.
    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'redemption_type', 'capital_settled', 'interest_settled', 'total_settled',
        'receipt_no', 'facility_payment_id', 'settled_by', 'settled_at',
    ];

    protected $casts = [
        'capital_settled' => 'decimal:2',
        'interest_settled' => 'decimal:2',
        'total_settled' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function facility()
    {
        return $this->belongsTo(Pawning::class, 'credit_facility_id');
    }

    public function payment()
    {
        return $this->belongsTo(FacilityPayment::class, 'facility_payment_id');
    }

    public function settledBy()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /** Which articles this redemption released. */
    public function items()
    {
        return $this->belongsToMany(
            FacilityCollateral::class,
            'pawning_redemption_items',
            'pawning_redemption_id',
            'facility_collateral_id'
        );
    }
}
