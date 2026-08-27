<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilitySchedule extends Model
{
    protected $fillable = [
        'credit_facility_id', 'installment_no', 'due_date',
        'principal_due', 'interest_due', 'penalty_due', 'total_due',
        'paid_amount', 'paid_at', 'status', 'waived_interest',
    ];

    protected $casts = [
        'due_date' => 'date',
        'principal_due' => 'decimal:2',
        'interest_due' => 'decimal:2',
        'penalty_due' => 'decimal:2',
        'total_due' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'waived_interest' => 'decimal:2',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function allocations()
    {
        return $this->hasMany(FacilityPaymentAllocation::class, 'facility_schedule_id');
    }

    /**
     * Same as allocations() but excluding rows whose parent payment was
     * voided — a void keeps the allocation row for history (see
     * SpeedDraftPaymentController::destroy()/LoanPaymentController's
     * equivalent) but its principal/interest/penalty must stop counting as
     * "already applied" once voided. Interest's own paid_amount/status are
     * decremented directly on void so they self-correct either way, but
     * penalty has no persisted column (see SpeedDraftSummary's docblock) —
     * every penalty balance is netted straight off this relation, so it MUST
     * exclude voided payments or a voided penalty payment looks permanently
     * collected.
     */
    public function activeAllocations()
    {
        return $this->allocations()->whereHas('payment', fn ($q) => $q->where('status', '!=', 'Voided'));
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total_due - (float) $this->paid_amount, 2);
    }
}
