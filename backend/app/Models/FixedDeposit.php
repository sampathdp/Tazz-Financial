<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class FixedDeposit extends Model
{
    use \App\Models\Concerns\BelongsToBranch;
    use HasPublicUuid;

    protected $fillable = [
        'fd_number', 'branch_id', 'customer_id', 'customer_bank_detail_id', 'funding_product_id',
        'principal_amount', 'interest_rate_percent', 'interest_payment_method',
        'term_months', 'start_date', 'maturity_date',
        'monthly_interest_amount', 'total_interest_amount', 'maturity_amount', 'lien_amount', 'next_interest_date',
        'auto_renew', 'auto_renew_mode', 'auto_renew_term_months',
        'status', 'renewed_from_id', 'renewed_to_id', 'closed_at', 'created_by',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_rate_percent' => 'decimal:3',
        'start_date' => 'date:Y-m-d',
        'maturity_date' => 'date:Y-m-d',
        'monthly_interest_amount' => 'decimal:2',
        'total_interest_amount' => 'decimal:2',
        'maturity_amount' => 'decimal:2',
        'lien_amount' => 'decimal:2',
        'auto_renew' => 'boolean',
        'next_interest_date' => 'date:Y-m-d',
        'closed_at' => 'date:Y-m-d',
    ];

    protected $appends = ['is_matured', 'is_pledged'];

    // Reached its maturity date but hasn't been renewed or withdrawn yet — derived,
    // not a stored status, so there's no cron job needed to flip it (same idea as
    // LoanSchedule's unused-but-defined 'Overdue' status in this codebase).
    public function getIsMaturedAttribute(): bool
    {
        return $this->status === 'Active' && $this->maturity_date && Carbon::parse($this->maturity_date)->lte(Carbon::today());
    }

    /** Pledged to a loan - lien_amount is the maintained cache of the Active lien row. */
    public function getIsPledgedAttribute(): bool
    {
        return (float) $this->lien_amount > 0;
    }

    /** The customer account interest and maturity proceeds are paid to. */
    public function bankDetail()
    {
        return $this->belongsTo(CustomerBankDetail::class, 'customer_bank_detail_id')->withTrashed();
    }

    public function jointHolders()
    {
        return $this->hasMany(FdJointHolder::class);
    }

    public function nominees()
    {
        return $this->hasMany(FdNominee::class);
    }

    public function commissions()
    {
        return $this->hasMany(FdCommission::class)->orderBy('id');
    }

    public function liens()
    {
        return $this->hasMany(FacilityCollateralFdDetail::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function fundingProduct()
    {
        return $this->belongsTo(FundingProduct::class);
    }

    public function deposits()
    {
        return $this->hasMany(FdDeposit::class);
    }

    public function interestPayments()
    {
        return $this->hasMany(FdInterestPayment::class)->orderBy('installment_no');
    }

    public function withdrawal()
    {
        return $this->hasOne(FdWithdrawal::class);
    }

    public function partPayments()
    {
        return $this->hasMany(FdPartPayment::class)->orderByDesc('id');
    }

    public function renewedFrom()
    {
        return $this->belongsTo(FixedDeposit::class, 'renewed_from_id');
    }

    public function renewedTo()
    {
        return $this->belongsTo(FixedDeposit::class, 'renewed_to_id');
    }
}
