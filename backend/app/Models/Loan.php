<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'code', 'customer_id', 'loan_type_id',
        'principal_amount', 'down_payment', 'interest_rate_percent', 'interest_method', 'rate_period',
        'term_months', 'installment_frequency', 'purpose',
        'loan_officer_id', 'recovery_officer_id',
        'disbursed_amount', 'disbursed_at', 'first_installment_date',
        'status', 'applied_by', 'approved_by', 'approved_at', 'rejected_reason', 'closed_at', 'created_by',
        'is_terminated', 'terminated_at', 'is_legal', 'legal_at',
        'seizing_order_issued', 'seizing_order_at', 'is_repossessed', 'repossessed_at',
        'freeze_date', 'confirmed_date', 'confirmed_by',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'down_payment' => 'decimal:2',
        'interest_rate_percent' => 'decimal:3',
        'disbursed_amount' => 'decimal:2',
        'disbursed_at' => 'datetime',
        'first_installment_date' => 'date',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
        'is_terminated' => 'boolean',
        'terminated_at' => 'datetime',
        'is_legal' => 'boolean',
        'legal_at' => 'datetime',
        'seizing_order_issued' => 'boolean',
        'seizing_order_at' => 'datetime',
        'is_repossessed' => 'boolean',
        'repossessed_at' => 'datetime',
        'freeze_date' => 'date',
        'confirmed_date' => 'date',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function loanType()
    {
        return $this->belongsTo(LoanType::class);
    }

    public function loanOfficer()
    {
        return $this->belongsTo(User::class, 'loan_officer_id');
    }

    public function recoveryOfficer()
    {
        return $this->belongsTo(User::class, 'recovery_officer_id');
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

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function charges()
    {
        return $this->hasMany(LoanCharge::class);
    }

    public function postDatedCheques()
    {
        return $this->hasMany(LoanPostDatedCheque::class)->orderBy('cheque_date');
    }

    public function documents()
    {
        return $this->hasMany(LoanDocument::class);
    }

    public function diaryNotes()
    {
        return $this->hasMany(LoanDiaryNote::class)->orderByDesc('created_at');
    }

    public function waivers()
    {
        return $this->hasMany(LoanWaiver::class)->orderByDesc('waived_at');
    }

    public function guarantors()
    {
        return $this->hasMany(LoanGuarantor::class);
    }

    public function collateral()
    {
        return $this->hasMany(LoanCollateral::class);
    }

    public function schedules()
    {
        return $this->hasMany(LoanSchedule::class)->orderBy('installment_no');
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class)->orderByDesc('paid_at');
    }
}
