<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedDraftRenewal extends Model
{
    // No updated_at semantics worth tracking — renewed_at is the domain timestamp.
    protected $fillable = [
        'old_credit_facility_id', 'new_credit_facility_id',
        'capital_before', 'interest_due', 'interest_collected',
        'penalty_due', 'penalty_collected', 'capital_payment', 'capital_after',
        'new_term_months', 'facility_payment_id', 'renewed_by', 'renewed_at', 'notes',
    ];

    protected $casts = [
        'capital_before' => 'decimal:2',
        'interest_due' => 'decimal:2',
        'interest_collected' => 'decimal:2',
        'penalty_due' => 'decimal:2',
        'penalty_collected' => 'decimal:2',
        'capital_payment' => 'decimal:2',
        'capital_after' => 'decimal:2',
        'new_term_months' => 'integer',
        'renewed_at' => 'datetime',
    ];

    public function oldSpeedDraft()
    {
        return $this->belongsTo(SpeedDraft::class, 'old_credit_facility_id');
    }

    public function newSpeedDraft()
    {
        return $this->belongsTo(SpeedDraft::class, 'new_credit_facility_id');
    }

    public function payment()
    {
        return $this->belongsTo(FacilityPayment::class, 'facility_payment_id');
    }

    public function renewedBy()
    {
        return $this->belongsTo(User::class, 'renewed_by');
    }
}
