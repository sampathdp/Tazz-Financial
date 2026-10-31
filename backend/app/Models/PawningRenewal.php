<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PawningRenewal extends Model
{
    use \App\Models\Concerns\HasVoidedRows;

    // No created_at/updated_at columns on this table — renewed_at is the domain timestamp.
    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'renewal_no', 'old_due_date', 'new_due_date',
        'capital_before', 'interest_settled', 'capital_after', 'applied_rate_percent',
        'facility_payment_id', 'renewed_by', 'renewed_at', 'remark',
    ];

    protected $casts = [
        'renewal_no' => 'integer',
        'old_due_date' => 'date',
        'new_due_date' => 'date',
        'capital_before' => 'decimal:2',
        'interest_settled' => 'decimal:2',
        'capital_after' => 'decimal:2',
        'applied_rate_percent' => 'decimal:3',
        'renewed_at' => 'datetime',
    ];

    public function facility()
    {
        return $this->belongsTo(Pawning::class, 'credit_facility_id');
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
