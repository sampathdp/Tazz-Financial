<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\LoanPaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LoanPaymentController extends Controller
{
    public function index(Loan $loan)
    {
        return $loan->payments()->with(['receiver:id,name', 'voider:id,name'])->get();
    }

    public function store(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Active') {
            return response()->json(['message' => 'Payments can only be recorded against an active loan.'], 422);
        }
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            'paid_at' => 'nullable|date',
            'receipt_no' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:255',
        ]);

        $schedules = $loan->schedules()->where('status', '!=', 'Paid')->orderBy('installment_no')->get();
        $outstanding = round($schedules->sum(fn ($s) => (float) $s->total_due - (float) $s->paid_amount), 2);
        if ($data['amount'] > $outstanding) {
            return response()->json(['message' => "Amount exceeds the outstanding balance ({$outstanding})."], 422);
        }

        $paidByRow = LoanPaymentAllocation::whereIn('loan_schedule_id', $schedules->pluck('id'))
            ->selectRaw('loan_schedule_id, SUM(interest_applied) as interest_applied, SUM(principal_applied) as principal_applied')
            ->groupBy('loan_schedule_id')->get()->keyBy('loan_schedule_id');

        $payment = DB::transaction(function () use ($loan, $data, $schedules, $paidByRow, $request) {
            $max = LoanPayment::lockForUpdate()->max('id') ?? 0;
            $payment = LoanPayment::create([
                'code' => 'PMT-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT),
                'loan_id' => $loan->id,
                'branch_id' => $loan->branch_id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? 'Cash',
                'received_by' => $request->user()->id,
                'paid_at' => $data['paid_at'] ?? now(),
                'receipt_no' => $data['receipt_no'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $remaining = (float) $data['amount'];
            foreach ($schedules as $row) {
                if ($remaining <= 0) {
                    break;
                }
                $balance = round((float) $row->total_due - (float) $row->paid_amount, 2);
                if ($balance <= 0) {
                    continue;
                }
                $applied = min($remaining, $balance);
                $priorInterest = (float) ($paidByRow[$row->id]->interest_applied ?? 0);
                $interestRemaining = max(0, round((float) $row->interest_due - $priorInterest, 2));
                $interestApplied = min($applied, $interestRemaining);
                $principalApplied = round($applied - $interestApplied, 2);

                LoanPaymentAllocation::create([
                    'loan_payment_id' => $payment->id,
                    'loan_schedule_id' => $row->id,
                    'principal_applied' => $principalApplied,
                    'interest_applied' => $interestApplied,
                    'penalty_applied' => 0,
                ]);

                $newPaid = round((float) $row->paid_amount + $applied, 2);
                $row->update([
                    'paid_amount' => $newPaid,
                    'status' => $newPaid >= (float) $row->total_due ? 'Paid' : 'Partial',
                    'paid_at' => $newPaid >= (float) $row->total_due ? now() : $row->paid_at,
                ]);

                $remaining = round($remaining - $applied, 2);
            }

            $stillOwed = $loan->schedules()->get()->sum(fn ($s) => (float) $s->total_due - (float) $s->paid_amount);
            if (round($stillOwed, 2) <= 0) {
                $loan->update(['status' => 'Closed', 'closed_at' => now()]);
            }

            return $payment;
        });

        return response()->json($payment->load('receiver:id,name'), 201);
    }

    public function destroy(Request $request, LoanPayment $payment)
    {
        if ($payment->status === 'Voided') {
            return response()->json(['message' => 'This payment is already voided.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($payment, $data, $request) {
            foreach ($payment->allocations as $alloc) {
                $row = $alloc->schedule;
                $newPaid = max(0, round((float) $row->paid_amount - $alloc->principal_applied - $alloc->interest_applied - $alloc->penalty_applied, 2));
                $row->update([
                    'paid_amount' => $newPaid,
                    'status' => $newPaid <= 0 ? 'Pending' : ($newPaid >= (float) $row->total_due ? 'Paid' : 'Partial'),
                    'paid_at' => $newPaid >= (float) $row->total_due ? $row->paid_at : null,
                ]);
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
