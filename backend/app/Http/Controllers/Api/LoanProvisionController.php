<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Support\LoanProvisioning;
use Illuminate\Http\Request;

/**
 * View + manually post/recover a loan's impairment provision. Classification
 * (npl_flag, provision_required_*) is kept live by LoanProvisioning::
 * refreshCache() — the daily loans:recompute-provisioning command and the
 * inline hook in LoanPaymentAllocator::finalizeApplied() — so this
 * controller only ever reads that cache for the worklist and calls
 * LoanProvisioning::post() to actually book the ledger entry.
 */
class LoanProvisionController extends Controller
{
    private const RELATIONS = [
        'customer:id,full_name,code,nic_no',
        'branch:id,name,code',
        'loanDetail.loanType:id,name,code',
    ];

    /** Every Active loan currently NPL, or with a required/posted provision mismatch (i.e. something to post or recover) — everything else needs no attention on this screen. */
    public function index()
    {
        $loans = Loan::whereIn('status', ['Active', 'Lapsed'])
            ->where(function ($q) {
                $q->where('npl_flag', true)
                    ->orWhereColumn('provision_required_amount', '!=', 'provision_posted_amount');
            })
            ->with(self::RELATIONS)
            ->orderByDesc('provision_required_amount')
            ->get();

        return $loans->map(fn (Loan $loan) => [
            'id' => $loan->id,
            'code' => $loan->code,
            'customer' => $loan->customer,
            'branch' => $loan->branch,
            'loanType' => $loan->loanDetail?->loanType,
            'nplFlag' => (bool) $loan->npl_flag,
            'provisionRequiredPct' => (int) $loan->provision_required_pct,
            'provisionRequiredAmount' => (float) $loan->provision_required_amount,
            'provisionPostedAmount' => (float) $loan->provision_posted_amount,
            'provisionDelta' => round((float) $loan->provision_required_amount - (float) $loan->provision_posted_amount, 2),
            'provisionLastEvaluatedAt' => $loan->provision_last_evaluated_at,
        ])->values();
    }

    /** Books the delta between required and posted for this one loan — posted:false (no LoanProvision row, nothing booked) if they already matched going in. */
    public function post(Request $request, Loan $loan)
    {
        $provision = LoanProvisioning::post($loan, $request->user()->id);
        $loan->refresh()->load(self::RELATIONS);

        return [
            'posted' => (bool) $provision,
            'loan' => [
                'id' => $loan->id,
                'code' => $loan->code,
                'nplFlag' => (bool) $loan->npl_flag,
                'provisionRequiredPct' => (int) $loan->provision_required_pct,
                'provisionRequiredAmount' => (float) $loan->provision_required_amount,
                'provisionPostedAmount' => (float) $loan->provision_posted_amount,
                'provisionDelta' => round((float) $loan->provision_required_amount - (float) $loan->provision_posted_amount, 2),
            ],
        ];
    }

    /** Post/Recover audit trail for one loan, newest first. */
    public function history(Loan $loan)
    {
        return $loan->provisions()->with('postedBy:id,name')->get();
    }
}
