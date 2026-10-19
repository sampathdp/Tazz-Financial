<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A credit_facilities row where facility_type = 'Pawning'. Shares the
 * credit_facilities table (and facility_payments/facility_status_events)
 * with Loan/SpeedDraft — see the credit_facilities migration for the
 * shared-header rationale. Pawning-specific columns (scheme, applied rate,
 * repay_due_date, renewal chain) live on the 1:1 pawningDetail relation
 * (pawning_facility_details), accessed explicitly, never proxied.
 * facility_schedules is deliberately not used here — pawning interest is
 * flat/settled-at-maturity, not amortized into installments.
 */
class Pawning extends Model
{
    use BelongsToBranch, HasPublicUuid, SoftDeletes;

    protected $table = 'credit_facilities';

    protected $fillable = [
        'branch_id', 'code', 'customer_id',
        'principal_amount', 'interest_rate_percent', 'rate_period', 'term_months',
        'start_date', 'maturity_date',
        'disbursed_amount', 'disbursed_at', 'outstanding_balance_cache',
        'status', 'applied_by', 'approved_by', 'approved_at', 'rejected_reason', 'closed_at', 'created_by',
        'cancelled_reason', 'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_rate_percent' => 'decimal:3',
        'term_months' => 'integer',
        'start_date' => 'date',
        'maturity_date' => 'date',
        'disbursed_amount' => 'decimal:2',
        'disbursed_at' => 'datetime',
        'outstanding_balance_cache' => 'decimal:2',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('facilityType', function (Builder $builder) {
            $builder->where('credit_facilities.facility_type', 'Pawning');
        });

        static::creating(function (Pawning $pawning) {
            Customer::assertNotBlacklisted($pawning->customer_id);
            $pawning->facility_type = 'Pawning';
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /** The pawning-specific attributes for this facility — always present for a Pawning row. */
    public function pawningDetail()
    {
        return $this->hasOne(PawningFacilityDetail::class, 'credit_facility_id');
    }

    public function applier()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Overdue/notice/auction-listed lifecycle events, newest first. */
    public function statusEvents()
    {
        return $this->hasMany(FacilityStatusEvent::class, 'credit_facility_id')->orderByDesc('created_at');
    }

    /** The pledged articles — collateral_type = PawnedJewelry on every row for this facility. */
    public function collateral()
    {
        return $this->hasMany(FacilityCollateral::class, 'credit_facility_id');
    }

    public function payments()
    {
        return $this->hasMany(FacilityPayment::class, 'credit_facility_id')->orderByDesc('paid_at');
    }

    public function renewals()
    {
        return $this->hasMany(PawningRenewal::class, 'credit_facility_id')->orderByDesc('renewal_no');
    }

    public function redemptions()
    {
        return $this->hasMany(PawningRedemption::class, 'credit_facility_id')->orderByDesc('settled_at');
    }
}
