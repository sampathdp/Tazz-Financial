<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cash one branch lends another, routed through Head Office. Deliberately not branch-scoped: it belongs to two
 * branches at once, so visibility is decided in BranchFundingController (a branch sees agreements it is party to).
 */
class BranchFundingAgreement extends Model
{
    protected $fillable = [
        'code', 'lender_branch_id', 'borrower_branch_id', 'lender_account_id', 'borrower_account_id',
        'principal_amount', 'outstanding_principal', 'interest_rate_percent', 'accrual_mode',
        'interest_accrued_through', 'accrued_interest_unpaid', 'request_date', 'disbursed_on',
        'purpose', 'notes', 'status', 'requested_by', 'approved_by',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'outstanding_principal' => 'decimal:2',
        'interest_rate_percent' => 'decimal:3',
        'accrued_interest_unpaid' => 'decimal:2',
        'interest_accrued_through' => 'date:Y-m-d',
        'request_date' => 'date:Y-m-d',
        'disbursed_on' => 'date:Y-m-d',
    ];

    public function lenderBranch()
    {
        return $this->belongsTo(Branch::class, 'lender_branch_id');
    }

    public function borrowerBranch()
    {
        return $this->belongsTo(Branch::class, 'borrower_branch_id');
    }

    public function transactions()
    {
        return $this->hasMany(BranchFundingTransaction::class, 'agreement_id')->orderBy('txn_date')->orderBy('id');
    }
}
