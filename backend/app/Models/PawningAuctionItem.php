<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PawningAuctionItem extends Model
{
    protected $fillable = [
        'pawning_auction_id', 'facility_collateral_id', 'credit_facility_id',
        'outstanding_capital', 'outstanding_interest', 'reserve_price', 'hammer_price',
        'buyer_name', 'buyer_nic', 'surplus_amount', 'settlement_status', 'status', 'sold_at',
    ];

    protected $casts = [
        'outstanding_capital' => 'decimal:2',
        'outstanding_interest' => 'decimal:2',
        'reserve_price' => 'decimal:2',
        'hammer_price' => 'decimal:2',
        'surplus_amount' => 'decimal:2',
        'sold_at' => 'datetime',
    ];

    public function auction()
    {
        return $this->belongsTo(PawningAuction::class, 'pawning_auction_id');
    }

    public function collateral()
    {
        return $this->belongsTo(FacilityCollateral::class, 'facility_collateral_id');
    }

    public function facility()
    {
        return $this->belongsTo(Pawning::class, 'credit_facility_id');
    }
}
