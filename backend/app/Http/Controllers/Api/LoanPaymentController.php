<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Services\NumberGenerator;
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
        return $loan->payments()->with(['receiver:id,name', 'approver:id,name', 'voider:id,name'])->get();
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

    public function store(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Active') {
            return response()->json(['message' => 'Payments can only be recorded against an active loan.'], 422);
        }
        if ($loan->payments()->where('is_settlement', true)->where('approval_status', 'PendingApproval')->exists()) {
            return response()->json(['message' => 'A full settlement request is already pending approval for this loan — approve or reject it before recording further payments.'], 422);
        }
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            'paid_at' => 'nullable|date',
            'receipt_no' => 'nullable|string|max:32',
            'bank_name' => 'required_if:payment_method,Cheque|nullable|string|max:191',
            'cheque_date' => 'required_if:payment_method,Cheque|nullable|date',
            'notes' => 'nullable|string|max:255',
            'is_settlement' => 'boolean',
        ]);
        $isSettlement = (bool) ($data['is_settlement'] ?? false);
        $isCheque = ($data['payment_method'] ?? 'Cash') === 'Cheque';

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
        if ($isSettlement) {
            $data['amount'] = $outstanding;
        } elseif ($data['amount'] > $outstanding) {
            return response()->json(['message' => "Amount exceeds the outstanding balance ({$outstanding})."], 422);
        }

        $payment = DB::transaction(function () use ($loan, $data, $isSettlement, $isCheque, $request) {
            $branch = Branch::find($loan->branch_id);
            $method = $data['payment_method'] ?? 'Cash';
            $code = NumberGenerator::next('payment', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'type_code' => self::TYPE_CODES[$method] ?? 'CA',
                'user_id' => $request->user()->id,
            ]);
            $payment = LoanPayment::create([
                'code' => $code,
                'loan_id' => $loan->id,
                'branch_id' => $loan->branch_id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? 'Cash',
                // Cash/Bank and settlement payments have no clearance concept (null).
                // A regular cheque payment starts Pending — see clearCheque().
                'clearance_status' => (!$isSettlement && $isCheque) ? 'Pending' : null,
                'received_by' => $request->user()->id,
                'paid_at' => $data['paid_at'] ?? now(),
                'receipt_no' => $data['receipt_no'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'cheque_date' => $data['cheque_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_settlement' => $isSettlement,
                'approval_status' => $isSettlement ? 'PendingApproval' : 'Approved',
            ]);
            NumberGenerator::attach($code, $payment);

            // A settlement request just reserves the amount for review — nothing is
            // applied to the schedule until it's approved. A regular cheque payment
            // similarly reserves the amount until the cheque is manually realized
            // (it can still bounce) — see clearCheque().
            if (!$isSettlement && !$isCheque) {
                LoanPaymentAllocator::applyAllocation($loan, $payment, (float) $data['amount']);
            }

            return $payment;
        });

        return response()->json($payment->load('receiver:id,name'), 201);
    }

    public function approveSettlement(Request $request, LoanPayment $payment)
    {
        if (!$payment->is_settlement || $payment->approval_status !== 'PendingApproval') {
            return response()->json(['message' => 'This payment is not a pending settlement request.'], 422);
        }
        $loan = $payment->loan;
        $outstanding = LoanPaymentAllocator::outstandingBalance($loan);
        if ((float) $payment->amount > $outstanding) {
            return response()->json(['message' => "The outstanding balance has changed since this request was made (now {$outstanding}). Reject it and ask the cashier to resubmit."], 422);
        }

        DB::transaction(function () use ($loan, $payment, $request) {
            LoanPaymentAllocator::applyAllocation($loan, $payment, (float) $payment->amount);
            $payment->update([
                'approval_status' => 'Approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
        });

        return $payment->fresh()->load('receiver:id,name', 'approver:id,name');
    }

    public function rejectSettlement(Request $request, LoanPayment $payment)
    {
        if (!$payment->is_settlement || $payment->approval_status !== 'PendingApproval') {
            return response()->json(['message' => 'This payment is not a pending settlement request.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);

        $payment->update([
            'approval_status' => 'Rejected',
            'status' => 'Voided',
            'void_reason' => $data['void_reason'] ?? null,
            'voided_by' => $request->user()->id,
            'voided_at' => now(),
        ]);

        return $payment->fresh()->load('voider:id,name');
    }

    /** Manually realize or bounce a cheque payment that's been held pending clearance. */
    public function clearCheque(Request $request, LoanPayment $payment)
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
            $loan = $payment->loan;
            $asOf = Carbon::parse($data['realized_at']);
            $outstanding = LoanPaymentAllocator::outstandingBalance($loan, $asOf);
            if ((float) $payment->amount > $outstanding) {
                return response()->json(['message' => "The outstanding balance has changed since this cheque was recorded (now {$outstanding}). Reject it and ask the cashier to re-record the payment."], 422);
            }
            DB::transaction(function () use ($loan, $payment, $asOf) {
                LoanPaymentAllocator::applyAllocation($loan, $payment, (float) $payment->amount, $asOf);
                $payment->update(['clearance_status' => 'Realized', 'realized_at' => $asOf]);
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

    public function destroy(Request $request, LoanPayment $payment)
    {
        if ($payment->status === 'Voided') {
            return response()->json(['message' => 'This payment is already voided.'], 422);
        }
        if ($payment->is_settlement && $payment->approval_status === 'PendingApproval') {
            return response()->json(['message' => 'Reject the pending settlement request instead of voiding it.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);
        $payment->load(['allocations.schedule', 'chargeAllocations.charge']);

        DB::transaction(function () use ($payment, $data, $request) {
            foreach ($payment->allocations as $alloc) {
                $row = $alloc->schedule;
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
            $loan = $payment->loan;
            if ($loan->status === 'Closed') {
                $loan->update(['status' => 'Active', 'closed_at' => null]);
            }
            // Kept as a voided row (not deleted) so it still appears as a
            // "Cancelled Receipt" on the loan's history rather than vanishing.
            $payment->update([
                'status' => 'Voided',
                'void_reason' => $data['void_reason'] ?? null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);
        });

        return response()->noContent();
    }

}
