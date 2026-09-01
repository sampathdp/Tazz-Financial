<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FdInterestPayment;
use App\Models\FixedDeposit;
use App\Support\FdLedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FdInterestPaymentController extends Controller
{
    private const PAYMENT_METHODS = ['Cash', 'Bank', 'Cheque'];

    public function index(FixedDeposit $fixedDeposit)
    {
        return $fixedDeposit->interestPayments()->with('payer:id,name')->get();
    }

    /**
     * Every monthly interest installment still Pending on an Active FD that is
     * due on or before the end of the current month, split into release
     * buckets — Overdue (past due, still unpaid), Today, This Week (through the
     * end of this week), and This Month (the rest, through month end). Powers
     * the "Interest Due" screen so the desk can see, at a glance, which FD
     * interest payments have to be released now / this week / this month.
     */
    public function due()
    {
        $today = now()->startOfDay();
        $endOfWeek = now()->endOfWeek();
        $endOfMonth = now()->endOfMonth();

        $rows = FdInterestPayment::query()
            ->where('status', 'Pending')
            ->whereDate('due_date', '<=', $endOfMonth)
            ->whereHas('fixedDeposit', fn ($q) => $q->where('status', 'Active'))
            ->with(['fixedDeposit:id,fd_number,customer_id', 'fixedDeposit.customer:id,full_name,code,nic_no,phone'])
            ->orderBy('due_date')
            ->orderBy('fixed_deposit_id')
            ->get();

        $buckets = ['overdue' => [], 'today' => [], 'this_week' => [], 'this_month' => []];
        foreach ($rows as $row) {
            $due = \Carbon\Carbon::parse($row->due_date)->startOfDay();
            $key = match (true) {
                $due->lt($today) => 'overdue',
                $due->eq($today) => 'today',
                $due->lte($endOfWeek) => 'this_week',
                default => 'this_month',
            };
            $buckets[$key][] = $row;
        }

        $shape = fn (array $items) => [
            'count' => count($items),
            'total' => round(array_sum(array_map(fn ($i) => (float) $i->amount, $items)), 2),
            'items' => array_values($items),
        ];

        return [
            'as_of' => $today->format('Y-m-d'),
            'end_of_week' => $endOfWeek->format('Y-m-d'),
            'end_of_month' => $endOfMonth->format('Y-m-d'),
            'buckets' => [
                'overdue' => $shape($buckets['overdue']),
                'today' => $shape($buckets['today']),
                'this_week' => $shape($buckets['this_week']),
                'this_month' => $shape($buckets['this_month']),
            ],
        ];
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
        if (now()->format('Y-m-d') < $fdInterestPayment->due_date) {
            return response()->json(['message' => 'Interest cannot be paid before the due date.'], 422);
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

            FdLedgerPoster::postInterestPaid($fdInterestPayment->fresh());
        });

        return $fdInterestPayment->fresh(['fixedDeposit:id,fd_number,customer_id', 'payer:id,name']);
    }

    /** Undoes a mistaken interest payment — reverses the ledger/till and puts the installment back to Pending. */
    public function void(Request $request, FdInterestPayment $fdInterestPayment)
    {
        if ($fdInterestPayment->status !== 'Paid') {
            return response()->json(['message' => 'This installment has not been paid.'], 422);
        }
        $fdInterestPayment->loadMissing('fixedDeposit');
        $fd = $fdInterestPayment->fixedDeposit;

        DB::transaction(function () use ($fdInterestPayment, $fd) {
            FdLedgerPoster::reverseInterestPaid($fdInterestPayment);
            $fdInterestPayment->update([
                'status' => 'Pending',
                'paid_amount' => 0,
                'paid_at' => null,
                'payment_method' => null,
                'reference_no' => null,
                'paid_by' => null,
            ]);
            // This installment is Pending again — if it's now the earliest
            // one, next_interest_date needs to point back at it rather than
            // whatever installment was next after it.
            $earliestPending = $fd->interestPayments()->where('status', 'Pending')->orderBy('due_date')->first();
            $fd->update(['next_interest_date' => $earliestPending?->due_date]);
        });

        return $fdInterestPayment->fresh(['fixedDeposit:id,fd_number,customer_id', 'payer:id,name']);
    }
}
