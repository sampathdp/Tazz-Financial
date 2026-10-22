<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\Branch;
use App\Models\FacilityPayment;
use App\Models\Loan;
use App\Services\NumberGenerator;
use App\Support\LoanLedgerPoster;
use App\Support\LoanPaymentAllocator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LoanPaymentController extends Controller
{
    /** Short code used in the receipt number's {TYPE} segment — see NumberGenerator's 'payment' format. */
    private const TYPE_CODES = ['Cash' => 'CA', 'Bank' => 'BT', 'Cheque' => 'CQ'];

    public function index(Loan $loan)
    {
        return $loan->payments()->with(['receiver:id,name', 'approver:id,name', 'voider:id,name', 'bank:id,name', 'pendingVoidRequest.requester:id,name', 'pendingAllocationRequest.requester:id,name'])->get();
    }

    /** Preview-only: the receipt number the next payment on this loan would get,
     *  for display in the payment form before it's submitted — see NumberGenerator::peek(). */
    public function nextReceiptNo(Request $request, Loan $loan)
    {
        $data = $request->validate(['payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])]]);
        $branch = Branch::find($loan->branch_id);
        $method = $data['payment_method'] ?? 'Cash';
        $code = NumberGenerator::peek('payment', [
            'branch_code' => $branch->code,
            'type_code' => self::TYPE_CODES[$method] ?? 'CA',
        ]);

        return response()->json(['code' => $code]);
    }

    /** Preview-only: what's currently due per bucket (Interest/OD/Other Charges/
     *  Capital) — powers the Make Payment modal's manual-allocation breakdown, so
     *  the cashier sees the same caps store() will validate against before
     *  submitting. See LoanPaymentAllocator::outstandingByBucket(). */
    public function outstandingByBucket(Request $request, Loan $loan)
    {
        $data = $request->validate(['as_of' => 'nullable|date']);
        $asOf = isset($data['as_of']) ? Carbon::parse($data['as_of']) : null;

        return response()->json(LoanPaymentAllocator::outstandingByBucket($loan, $asOf));
    }

    public function store(Request $request, Loan $loan)
    {
        // Active covers normal loan servicing; Approved covers a pre-disbursement
        // charge (e.g. Document) being paid off before the loan generates a
        // schedule — see LoanController::disburse()'s gate on that charge.
        if (!in_array($loan->status, ['Active', 'Approved'], true)) {
            return response()->json(['message' => 'Payments can only be recorded against an active or approved loan.'], 422);
        }
        if ($loan->payments()->where('payment_type', 'Settlement')->where('approval_status', 'PendingApproval')->exists()) {
            return response()->json(['message' => 'A full settlement request is already pending approval for this loan — approve or reject it before recording further payments.'], 422);
        }
        if ($loan->payments()->where('payment_type', 'ManualAllocation')->where('approval_status', 'PendingApproval')->exists()) {
            return response()->json(['message' => 'A manual allocation request is already pending approval for this loan — approve or reject it before recording further payments.'], 422);
        }
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            'paid_at' => 'nullable|date',
            'receipt_no' => 'nullable|string|max:32',
            'bank_id' => 'required_if:payment_method,Cheque|nullable|exists:banks,id',
            'cheque_date' => 'required_if:payment_method,Cheque|nullable|date',
            'notes' => 'nullable|string|max:255',
            'collected_branch_id' => 'nullable|exists:branches,id',
            'is_settlement' => 'boolean',
            'allocation_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'allocations' => 'required_if:allocation_mode,manual|array',
            'allocations.interest' => 'nullable|numeric|min:0',
            'allocations.capital' => 'nullable|numeric|min:0',
            'allocations.od_charge' => 'nullable|numeric|min:0',
            'allocations.other_charges' => 'nullable|numeric|min:0',
        ]);
        // Taking another branch's customer's cash at this counter is for roles that work across branches; the
        // cash goes to the collecting branch's till and the two branches settle through the inter-branch accounts.
        if (!empty($data['collected_branch_id']) && (int) $data['collected_branch_id'] !== (int) $loan->branch_id
            && !($request->user()->role && $request->user()->role->can_view_all_branches)) {
            return response()->json(['message' => 'Only users who work across branches can record a payment collected at another branch.'], 403);
        }
        // A receipt can't be dated in the future, nor before the loan existed (its money went out on
        // disbursed_at; an undisbursed loan - a Document-charge receipt - dates from its application).
        if (!empty($data['paid_at'])) {
            $paidOn = Carbon::parse($data['paid_at'])->startOfDay();
            $floor = ($loan->disbursed_at ?? $loan->loanDetail?->application_date ?? $loan->created_at)?->copy()->startOfDay();
            if ($paidOn->gt(Carbon::today())) {
                return response()->json(['message' => 'The payment date cannot be in the future.'], 422);
            }
            if ($floor && $paidOn->lt($floor)) {
                return response()->json(['message' => 'The payment date cannot be before ' . $floor->toDateString() . ' (when this loan was ' . ($loan->disbursed_at ? 'disbursed' : 'applied for') . ').'], 422);
            }
        }
        $isSettlement = (bool) ($data['is_settlement'] ?? false);
        $isManual = ($data['allocation_mode'] ?? 'auto') === 'manual';
        $isCheque = ($data['payment_method'] ?? 'Cash') === 'Cheque';

        if ($isManual && $isSettlement) {
            return response()->json(['message' => 'A payment cannot be both a full settlement and a manual allocation.'], 422);
        }

        // Full settlement pays off and closes an active loan — meaningless before
        // the loan has even been disbursed.
        if ($isSettlement && $loan->status !== 'Active') {
            return response()->json(['message' => 'A loan can only be settled once it is active.'], 422);
        }

        // Full settlement is a distinct, higher-stakes action (pays off the whole
        // loan at once and requires a second person's approval to close it) — kept
        // to the Cashier role specifically, not just anyone who can log a payment.
        if ($isSettlement && !$request->user()->isAdmin() && $request->user()->role?->slug !== 'cashier') {
            return response()->json(['message' => 'Only a Cashier can apply for a full settlement.'], 403);
        }

        $outstanding = LoanPaymentAllocator::outstandingBalance($loan);
        if ($outstanding <= 0) {
            return response()->json(['message' => 'This loan has no outstanding balance.'], 422);
        }
        // Full settlement always targets the current outstanding balance —
        // computed server-side so a stale client value can't under/overstate it.
        // It's quoted as of the payment's own date, not the full remaining
        // schedule: interest not yet accrued by then is waived, not collected
        // (see LoanPaymentAllocator::settlementQuote()).
        if ($isSettlement) {
            $asOf = Carbon::parse($data['paid_at'] ?? now());
            $data['amount'] = LoanPaymentAllocator::settlementQuote($loan, $asOf)['outstanding'];
        } elseif ($data['amount'] > $outstanding) {
            return response()->json(['message' => "Amount exceeds the outstanding balance ({$outstanding})."], 422);
        }

        $allocations = null;
        if ($isManual) {
            $allocations = [
                'interest' => round((float) ($data['allocations']['interest'] ?? 0), 2),
                'od_charge' => round((float) ($data['allocations']['od_charge'] ?? 0), 2),
                'other_charges' => round((float) ($data['allocations']['other_charges'] ?? 0), 2),
                'capital' => round((float) ($data['allocations']['capital'] ?? 0), 2),
            ];
            $sum = round(array_sum($allocations), 2);
            if (abs($sum - round((float) $data['amount'], 2)) > 0.01) {
                return response()->json(['message' => "The allocation split ({$sum}) must add up to the payment amount ({$data['amount']})."], 422);
            }
            $asOf = Carbon::parse($data['paid_at'] ?? now());
            $due = LoanPaymentAllocator::outstandingByBucket($loan, $asOf);
            $labels = ['interest' => 'Interest', 'od_charge' => 'OD Charges', 'other_charges' => 'Other Charges', 'capital' => 'Capital'];
            foreach ($allocations as $bucket => $amt) {
                if ($amt > $due[$bucket]) {
                    return response()->json(['message' => "{$labels[$bucket]} amount ({$amt}) exceeds what's due for that bucket ({$due[$bucket]})."], 422);
                }
            }
        }

        $payment = DB::transaction(function () use ($loan, $data, $isSettlement, $isManual, $isCheque, $allocations, $request) {
            // Serialize payments on one loan: without this lock two simultaneous receipts (a double
            // click, two cashiers) both read the same unpaid installment/charge, both allocate to it
            // and the cash is booked twice against a single amount due.
            Loan::whereKey($loan->id)->lockForUpdate()->first();
            if (!$isSettlement && !$isManual) {
                $outstandingNow = LoanPaymentAllocator::outstandingBalance($loan->fresh());
                if ((float) $data['amount'] > $outstandingNow + 0.005) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'amount' => "Amount exceeds the outstanding balance ({$outstandingNow}) - another payment may have just been recorded.",
                    ]);
                }
            }
            $branch = Branch::find($loan->branch_id);
            $method = $data['payment_method'] ?? 'Cash';
            $code = NumberGenerator::next('payment', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'type_code' => self::TYPE_CODES[$method] ?? 'CA',
                'user_id' => $request->user()->id,
            ]);
            $payment = FacilityPayment::create([
                'code' => $code,
                'credit_facility_id' => $loan->id,
                'branch_id' => $loan->branch_id,
                // Set only when the cash was taken at another branch's counter (see the B-8 migration).
                'collected_branch_id' => (!empty($data['collected_branch_id']) && (int) $data['collected_branch_id'] !== (int) $loan->branch_id) ? (int) $data['collected_branch_id'] : null,
                'amount' => $data['amount'],
                'payment_method' => $method,
                'payment_type' => $isSettlement ? 'Settlement' : ($isManual ? 'ManualAllocation' : 'Regular'),
                // Cash/Bank and settlement/manual-allocation payments have no
                // clearance concept (null). A regular cheque payment starts
                // Pending — see clearCheque().
                'clearance_status' => (!$isSettlement && !$isManual && $isCheque) ? 'Pending' : null,
                'received_by' => $request->user()->id,
                'paid_at' => $data['paid_at'] ?? now(),
                'receipt_no' => $data['receipt_no'] ?? null,
                'bank_id' => $data['bank_id'] ?? null,
                'cheque_date' => $data['cheque_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'approval_status' => ($isSettlement || $isManual) ? 'PendingApproval' : 'Approved',
                'manual_allocation' => $allocations,
            ]);
            NumberGenerator::attach($code, $payment);

            // A settlement or manual-allocation request just reserves the amount
            // for review — nothing is applied to the schedule until it's
            // approved. A regular cheque payment similarly reserves the amount
            // until the cheque is manually realized (it can still bounce) — see
            // clearCheque().
            if (!$isSettlement && !$isManual && !$isCheque) {
                LoanPaymentAllocator::applyAllocation($loan, $payment, (float) $data['amount']);
            }

            if ($isManual || $isSettlement) {
                $approvalCode = $isManual ? 'payment_manual_allocation' : 'payment_settlement';
                $type = ApprovalType::where('code', $approvalCode)->firstOrFail();
                ApprovalRequest::create([
                    'approval_type_id' => $type->id,
                    'subject_id' => $payment->id,
                    'requested_by' => $request->user()->id,
                    'requested_at' => now(),
                    'reason' => $data['notes'] ?? null,
                    'required_approvals' => $type->required_approvals,
                ]);
            }

            // Cash is physically in hand the moment it's recorded, independent of
            // whether it's a pending settlement/manual-allocation awaiting
            // approval — post it to the Cashbook now rather than only when/if the
            // allocation is applied.
            LoanLedgerPoster::postCashReceipt($payment);

            return $payment;
        });

        return response()->json($payment->load('receiver:id,name'), 201);
    }

    /** Maker-checker via the shared approval_requests table (code
     *  'payment_settlement') — mirrors approveAllocationRequest(). The
     *  allocation/ledger posting only runs once enough distinct approvals are
     *  in; the FacilityPayment keeps its own approval_status for the UI badge. */
    public function approveSettlement(Request $request, FacilityPayment $payment)
    {
        if ($payment->payment_type !== 'Settlement' || $payment->approval_status !== 'PendingApproval') {
            return response()->json(['message' => 'This payment is not a pending settlement request.'], 422);
        }
        $req = $payment->pendingSettlementRequest;
        if (!$req) {
            return response()->json(['message' => 'No pending approval request found for this settlement.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'payment_settlement')) {
            return response()->json(['message' => 'You are not an approver for loan payment settlements.'], 403);
        }
        if ($req->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own settlement request.'], 422);
        }
        if ($req->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this request.'], 422);
        }
        $loan = Loan::findOrFail($payment->credit_facility_id);
        // Re-quote as of the same date the settlement was originally requested
        // for — this only catches genuine drift (a new charge, an installment
        // paid off some other way) since then. Left for a human to reject-and-
        // resubmit rather than auto-rejected, because rejection also unwinds
        // the cash receipt.
        $quote = LoanPaymentAllocator::settlementQuote($loan, $payment->paid_at);
        if ((float) $payment->amount > $quote['outstanding']) {
            return response()->json(['message' => "The outstanding balance has changed since this request was made (now {$quote['outstanding']}). Reject it and ask the cashier to resubmit."], 422);
        }

        DB::transaction(function () use ($loan, $payment, $quote, $req, $request) {
            if (!$req->first_approved_by) {
                $req->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $req->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }

            if ($req->fresh()->approvalsCount() < $req->required_approvals) {
                return;
            }

            LoanPaymentAllocator::applySettlementWaiver($loan, $quote['waivedInterest']);
            LoanPaymentAllocator::applyAllocation($loan, $payment, (float) $payment->amount);
            LoanLedgerPoster::postSettlementApplied($payment);
            $payment->update([
                'approval_status' => 'Approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
            $req->update(['status' => 'Approved']);
        });

        return $payment->fresh()->load('receiver:id,name', 'approver:id,name', 'pendingSettlementRequest.firstApprover:id,name');
    }

    public function rejectSettlement(Request $request, FacilityPayment $payment)
    {
        if ($payment->payment_type !== 'Settlement' || $payment->approval_status !== 'PendingApproval') {
            return response()->json(['message' => 'This payment is not a pending settlement request.'], 422);
        }
        $req = $payment->pendingSettlementRequest;
        if ($req && !ApprovalType::isAuthorized($request->user(), 'payment_settlement')) {
            return response()->json(['message' => 'You are not an approver for loan payment settlements.'], 403);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($payment, $data, $req, $request) {
            $payment->update([
                'approval_status' => 'Rejected',
                'status' => 'Voided',
                'void_reason' => $data['void_reason'] ?? null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);
            // The cash was posted to the Cashbook at record time (see store()) — a
            // rejected settlement means it's being handed back, so unwind that entry.
            LoanLedgerPoster::reverseAllFor($payment, $request->user()->id);
            if ($req) {
                $req->update([
                    'status' => 'Rejected',
                    'rejected_by' => $request->user()->id,
                    'rejected_at' => now(),
                    'reject_reason' => $data['void_reason'] ?? null,
                ]);
            }
        });

        return $payment->fresh()->load('voider:id,name');
    }

    /** Manually realize or bounce a cheque payment that's been held pending clearance. */
    public function clearCheque(Request $request, FacilityPayment $payment)
    {
        if ($payment->payment_method !== 'Cheque' || $payment->clearance_status !== 'Pending') {
            return response()->json(['message' => 'This payment is not a pending cheque awaiting clearance.'], 422);
        }
        $data = $request->validate([
            'status' => ['required', Rule::in(['Realized', 'Bounced'])],
            // The date the cheque actually cleared at the bank (check-in date) —
            // required when realizing, since that's the day the amount is
            // considered to have landed in the loan, not the day someone
            // happens to click "Realize" here.
            'realized_at' => 'required_if:status,Realized|nullable|date',
        ]);

        if ($data['status'] === 'Realized') {
            $loan = Loan::findOrFail($payment->credit_facility_id);
            $asOf = Carbon::parse($data['realized_at']);
            $outstanding = LoanPaymentAllocator::outstandingBalance($loan, $asOf);
            if ((float) $payment->amount > $outstanding) {
                return response()->json(['message' => "The outstanding balance has changed since this cheque was recorded (now {$outstanding}). Reject it and ask the cashier to re-record the payment."], 422);
            }
            DB::transaction(function () use ($loan, $payment, $asOf) {
                LoanPaymentAllocator::applyAllocation($loan, $payment, (float) $payment->amount, $asOf);
                $payment->update(['clearance_status' => 'Realized', 'realized_at' => $asOf]);
                LoanLedgerPoster::postChequeRealized($payment);
            });
        } else {
            // Bounced — it was never applied to the schedule, so there's nothing to
            // reverse. Void it so it stops counting as a live payment while keeping
            // the audit trail (same "voided, not deleted" convention as destroy()).
            $payment->update([
                'clearance_status' => 'Bounced',
                'status' => 'Voided',
                'void_reason' => 'Cheque bounced',
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);
        }

        return $payment->fresh()->load('receiver:id,name');
    }

    /** Submits a request to void this payment — doesn't touch the schedule/
     *  ledger/cashbook itself, only takes effect once approveVoidRequest()
     *  collects enough distinct approvals (approval_types.required_approvals
     *  for code 'payment_void', snapshotted here). See that method for the
     *  actual reversal. */
    public function voidRequest(Request $request, FacilityPayment $payment)
    {
        if ($payment->status === 'Voided') {
            return response()->json(['message' => 'This payment is already voided.'], 422);
        }
        if ($payment->payment_method === 'FdAdjustment') {
            return response()->json(['message' => 'This repayment came from the FD being adjusted against the loan and cannot be voided.'], 422);
        }
        if ($payment->payment_type === 'Settlement' && $payment->approval_status === 'PendingApproval') {
            return response()->json(['message' => 'Reject the pending settlement request instead of voiding it.'], 422);
        }
        if ($payment->voidRequests()->where('status', 'PendingApproval')->exists()) {
            return response()->json(['message' => 'A cancel request is already pending approval for this receipt.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);
        $type = ApprovalType::where('code', 'payment_void')->firstOrFail();

        $voidRequest = ApprovalRequest::create([
            'approval_type_id' => $type->id,
            'subject_id' => $payment->id,
            'requested_by' => $request->user()->id,
            'requested_at' => now(),
            'reason' => $data['void_reason'] ?? null,
            'required_approvals' => $type->required_approvals,
        ]);

        return response()->json($voidRequest->load('requester:id,name'), 201);
    }

    public function approveVoidRequest(Request $request, ApprovalRequest $voidRequest)
    {
        if ($voidRequest->approvalType->code !== 'payment_void') {
            abort(404);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'payment_void')) {
            return response()->json(['message' => 'You are not an approver for payment cancellations.'], 403);
        }
        if ($voidRequest->status !== 'PendingApproval') {
            return response()->json(['message' => 'This request is not pending approval.'], 422);
        }
        if ($voidRequest->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own cancel request.'], 422);
        }
        if ($voidRequest->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this request.'], 422);
        }
        $payment = FacilityPayment::findOrFail($voidRequest->subject_id);
        if ($payment->status === 'Voided') {
            $voidRequest->update(['status' => 'Rejected', 'rejected_by' => $request->user()->id, 'rejected_at' => now(), 'reject_reason' => 'This payment was already voided by another action.']);

            return response()->json(['message' => 'This payment is already voided — the request has been auto-rejected.'], 422);
        }
        $payment->load(['allocations.schedule', 'chargeAllocations.charge']);

        DB::transaction(function () use ($voidRequest, $payment, $request) {
            if (!$voidRequest->first_approved_by) {
                $voidRequest->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $voidRequest->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }

            if ($voidRequest->fresh()->approvalsCount() < $voidRequest->required_approvals) {
                return;
            }

            foreach ($payment->allocations as $alloc) {
                $row = $alloc->schedule;
                // Undo the accrued-to-date interest waiver this settlement applied
                // (see LoanPaymentAllocator::applySettlementWaiver()) before
                // recomputing status below — voiding the settlement means the
                // loan carries on, so the full future interest is owed again.
                if ($payment->payment_type === 'Settlement' && (float) $row->waived_interest > 0) {
                    $restore = (float) $row->waived_interest;
                    $row->interest_due = round((float) $row->interest_due + $restore, 2);
                    $row->total_due = round((float) $row->total_due + $restore, 2);
                    $row->waived_interest = 0;
                }
                // paid_amount tracks principal+interest only (an installment's total_due
                // never includes penalty — see LoanAmortizer), so only reverse those two;
                // penalty_applied is netted off separately via LoanSummary's live estimate.
                $newPaid = max(0, round((float) $row->paid_amount - $alloc->principal_applied - $alloc->interest_applied, 2));
                $row->update([
                    'paid_amount' => $newPaid,
                    'status' => $newPaid <= 0 ? 'Pending' : ($newPaid >= (float) $row->total_due ? 'Paid' : 'Partial'),
                    'paid_at' => $newPaid >= (float) $row->total_due ? $row->paid_at : null,
                ]);
            }
            foreach ($payment->chargeAllocations as $chargeAlloc) {
                $charge = $chargeAlloc->charge;
                $charge->update(['paid_amount' => max(0, round((float) $charge->paid_amount - $chargeAlloc->amount_applied, 2))]);
            }
            $loan = Loan::findOrFail($payment->credit_facility_id);
            if ($loan->status === 'Closed') {
                $loan->update(['status' => 'Active', 'closed_at' => null]);
            }
            // Kept as a voided row (not deleted) so it still appears as a
            // "Cancelled Receipt" on the loan's history rather than vanishing.
            $payment->update([
                'status' => 'Voided',
                'void_reason' => $voidRequest->reason,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);
            $this->refreshOutstandingCache($loan);
            LoanLedgerPoster::reverseAllFor($payment, $request->user()->id);
            $voidRequest->update(['status' => 'Approved']);
        });

        return $voidRequest->fresh()->load('requester:id,name', 'firstApprover:id,name', 'secondApprover:id,name');
    }

    public function rejectVoidRequest(Request $request, ApprovalRequest $voidRequest)
    {
        if ($voidRequest->approvalType->code !== 'payment_void') {
            abort(404);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'payment_void')) {
            return response()->json(['message' => 'You are not an approver for payment cancellations.'], 403);
        }
        if ($voidRequest->status !== 'PendingApproval') {
            return response()->json(['message' => 'This request is not pending approval.'], 422);
        }
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);
        $voidRequest->update([
            'status' => 'Rejected',
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'reject_reason' => $data['reject_reason'] ?? null,
        ]);

        return $voidRequest->fresh()->load('requester:id,name', 'rejecter:id,name');
    }

    /** Applies a pending manual-allocation request's stored split once it's
     *  collected enough distinct approvals (approval_types.required_approvals
     *  for code 'payment_manual_allocation', snapshotted on the request) — see
     *  store() for where the request/reservation is created. */
    public function approveAllocationRequest(Request $request, ApprovalRequest $allocationRequest)
    {
        if ($allocationRequest->approvalType->code !== 'payment_manual_allocation') {
            abort(404);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'payment_manual_allocation')) {
            return response()->json(['message' => 'You are not an approver for manual payment allocations.'], 403);
        }
        if ($allocationRequest->status !== 'PendingApproval') {
            return response()->json(['message' => 'This request is not pending approval.'], 422);
        }
        if ($allocationRequest->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own allocation request.'], 422);
        }
        if ($allocationRequest->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this request.'], 422);
        }
        $payment = FacilityPayment::findOrFail($allocationRequest->subject_id);
        if ($payment->payment_type !== 'ManualAllocation' || $payment->approval_status !== 'PendingApproval') {
            $allocationRequest->update(['status' => 'Rejected', 'rejected_by' => $request->user()->id, 'rejected_at' => now(), 'reject_reason' => 'This payment is no longer pending — it was already voided or resolved by another action.']);

            return response()->json(['message' => 'This payment is no longer pending approval — the request has been auto-rejected.'], 422);
        }
        $loan = Loan::findOrFail($payment->credit_facility_id);
        // Re-validate against the current outstanding — matches
        // approveSettlement()'s drift check.
        $due = LoanPaymentAllocator::outstandingByBucket($loan, $payment->paid_at);
        foreach ((array) $payment->manual_allocation as $bucket => $amt) {
            if ((float) $amt > ($due[$bucket] ?? 0)) {
                return response()->json(['message' => "The outstanding balance has changed since this request was made (now {$due[$bucket]} for {$bucket}). Reject it and ask the cashier to resubmit."], 422);
            }
        }

        DB::transaction(function () use ($loan, $payment, $allocationRequest, $request) {
            if (!$allocationRequest->first_approved_by) {
                $allocationRequest->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $allocationRequest->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }

            if ($allocationRequest->fresh()->approvalsCount() < $allocationRequest->required_approvals) {
                return;
            }

            LoanPaymentAllocator::applyManualAllocation($loan, $payment, (array) $payment->manual_allocation, $payment->paid_at);
            LoanLedgerPoster::postManualAllocationApplied($payment);
            $payment->update([
                'approval_status' => 'Approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
            $allocationRequest->update(['status' => 'Approved']);
        });

        return $allocationRequest->fresh()->load('requester:id,name', 'firstApprover:id,name', 'secondApprover:id,name');
    }

    public function rejectAllocationRequest(Request $request, ApprovalRequest $allocationRequest)
    {
        if ($allocationRequest->approvalType->code !== 'payment_manual_allocation') {
            abort(404);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'payment_manual_allocation')) {
            return response()->json(['message' => 'You are not an approver for manual payment allocations.'], 403);
        }
        if ($allocationRequest->status !== 'PendingApproval') {
            return response()->json(['message' => 'This request is not pending approval.'], 422);
        }
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);
        $payment = FacilityPayment::findOrFail($allocationRequest->subject_id);

        DB::transaction(function () use ($payment, $allocationRequest, $data, $request) {
            if ($payment->payment_type === 'ManualAllocation' && $payment->approval_status === 'PendingApproval') {
                $payment->update([
                    'approval_status' => 'Rejected',
                    'status' => 'Voided',
                    'void_reason' => $data['reject_reason'] ?? null,
                    'voided_by' => $request->user()->id,
                    'voided_at' => now(),
                ]);
                // The cash was posted to the Cashbook at record time (see store()) —
                // a rejected allocation request means it's being handed back, so
                // unwind that entry.
                LoanLedgerPoster::reverseAllFor($payment, $request->user()->id);
            }
            $allocationRequest->update([
                'status' => 'Rejected',
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
                'reject_reason' => $data['reject_reason'] ?? null,
            ]);
        });

        return $allocationRequest->fresh()->load('requester:id,name', 'rejecter:id,name');
    }

    /** Keeps credit_facilities.outstanding_balance_cache in step with LoanPaymentAllocator::outstandingBalance() after every mutation that could change it — see that column's docblock on the credit_facilities migration. */
    private function refreshOutstandingCache(Loan $loan): void
    {
        $loan->update(['outstanding_balance_cache' => LoanPaymentAllocator::outstandingBalance($loan->fresh())]);
    }
}
