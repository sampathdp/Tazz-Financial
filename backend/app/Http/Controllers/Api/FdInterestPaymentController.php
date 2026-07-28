<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FdInterestPayment;
use App\Models\FixedDeposit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FdInterestPaymentController extends Controller
{
    private const PAYMENT_METHODS = ['Cash', 'Cheque', 'BankTransfer'];

    public function index(FixedDeposit $fixedDeposit)
    {
        return $fixedDeposit->interestPayments()->with('payer:id,name')->get();
    }

    public function pay(Request $request, FdInterestPayment $fdInterestPayment)
    {
        if ($fdInterestPayment->status === 'Paid') {
            return response()->json(['message' => 'This interest installment has already been paid.'], 422);
        }
        $fdInterestPayment->loadMissing('fixedDeposit');
        $fd = $fdInterestPayment->fixedDeposit;
        if ($fd->status !== 'Active') {
            return response()->json(['message' => 'Interest can only be paid on an active FD.'], 422);
        }

        $data = $request->validate([
            'paid_at' => 'nullable|date',
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'reference_no' => 'nullable|string|max:64',
        ]);

        DB::transaction(function () use ($fdInterestPayment, $data, $request, $fd) {
            $fdInterestPayment->update([
                'status' => 'Paid',
                'paid_amount' => $fdInterestPayment->amount,
                'paid_at' => $data['paid_at'] ?? now()->format('Y-m-d'),
                'payment_method' => $data['payment_method'],
                'reference_no' => $data['reference_no'] ?? null,
                'paid_by' => $request->user()->id,
            ]);

            $next = $fd->interestPayments()->where('status', 'Pending')->orderBy('due_date')->first();
            $fd->update(['next_interest_date' => $next?->due_date]);
        });

        return $fdInterestPayment->fresh(['fixedDeposit:id,fd_number,customer_id', 'payer:id,name']);
    }
}
