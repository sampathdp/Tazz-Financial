<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\LoanPostDatedCheque;
use App\Services\NumberGenerator;
use App\Support\LoanPaymentAllocator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LoanPostDatedChequeController extends Controller
{
    private const STATUSES = ['Pending', 'Handed', 'Realized', 'Bounced', 'Returned'];

    public function index(Loan $loan)
    {
        return $loan->postDatedCheques()->with('creator:id,name')->get();
    }

    /**
     * Flat, cross-loan list for the Cheque Management screen — every cheque the
     * user's branch scope can see. Merges two sources:
     *   - LoanPostDatedCheque: cheques handed over for future collection, with
     *     a real Pending/Handed/Realized/Bounced/Returned workflow.
     *   - LoanPayment rows recorded with paymentMethod=Cheque: held Pending
     *     (not applied to the balance) until manually cleared via
     *     LoanPaymentController::clearCheque() — see PaymentReceipt.tsx's
     *     "Cheque" payment method vs. "Record Cheque" (Post Dated Cheques).
     *     Settlement cheques are excluded while awaiting their own approval;
     *     once approved (or for a plain Cheque payment once realized) they
     *     read here as Realized, same as any other cleared cheque.
     */
    public function all(Request $request)
    {
        $status = $request->filled('status') ? $request->string('status')->toString() : null;
        $dueToday = $request->boolean('due_today');
        $today = now()->toDateString();

        $postDated = LoanPostDatedCheque::with([
            'loan:id,code,customer_id,branch_id',
            'loan.customer:id,full_name',
            'loan.branch:id,name,code',
            'creator:id,name',
        ])->get()->map(fn (LoanPostDatedCheque $c) => [
            'id' => $c->id,
            'source' => 'PostDated',
            'cheque_no' => $c->cheque_no,
            'bank_name' => $c->bank_name,
            'cheque_date' => optional($c->cheque_date)->toDateString(),
            'amount' => $c->amount,
            'status' => $c->status,
            'loan' => $this->loanSummary($c->loan),
            'creator' => $c->creator ? ['id' => $c->creator->id, 'name' => $c->creator->name] : null,
        ]);

        $payments = LoanPayment::with([
            'loan:id,code,customer_id,branch_id',
            'loan.customer:id,full_name',
            'loan.branch:id,name,code',
            'receiver:id,name',
        ])
            ->where('payment_method', 'Cheque')
            ->where('approval_status', '!=', 'PendingApproval')
            // A cheque realized from the Post Dated Cheques flow already
            // appears via $postDated above (with its own loan_payment_id
            // link) — excluding it here keeps it from listing twice.
            ->whereDoesntHave('postDatedCheque')
            // Keep live payments plus ones voided specifically because the cheque
            // bounced (so its rejection still shows up in the history); drop
            // payments voided for any other reason (a cancelled receipt entirely).
            ->where(fn ($q) => $q->where('status', '!=', 'Voided')->orWhere('clearance_status', 'Bounced'))
            ->get()->map(fn (LoanPayment $p) => [
                'id' => $p->id,
                'source' => 'Payment',
                'cheque_no' => $p->receipt_no,
                'bank_name' => $p->bank_name,
                'cheque_date' => optional($p->cheque_date)->toDateString(),
                'amount' => $p->amount,
                // A plain cheque payment carries its own Pending/Realized/Bounced
                // clearance_status; an approved settlement cheque has none (its
                // approval already implies it applied), so it reads as Realized.
                'status' => $p->clearance_status ?? 'Realized',
                'loan' => $this->loanSummary($p->loan),
                'creator' => $p->receiver ? ['id' => $p->receiver->id, 'name' => $p->receiver->name] : null,
            ]);

        $all = $postDated->concat($payments);
        if ($status) {
            $all = $all->where('status', $status);
        }
        if ($dueToday) {
            $all = $all->where('cheque_date', $today);
        }

        return $all->sortBy('cheque_date')->values();
    }

    private function loanSummary(?Loan $loan): ?array
    {
        if (!$loan) {
            return null;
        }

        return [
            'id' => $loan->id,
            'code' => $loan->code,
            'customer' => $loan->customer ? ['id' => $loan->customer->id, 'full_name' => $loan->customer->full_name] : null,
            'branch' => $loan->branch ? ['id' => $loan->branch->id, 'name' => $loan->branch->name, 'code' => $loan->branch->code] : null,
        ];
    }

    public function store(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'cheque_no' => 'required|string|max:32',
            'bank_name' => 'required|string|max:128',
            'cheque_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
        ]);

        $cheque = $loan->postDatedCheques()->create([
            'branch_id' => $loan->branch_id,
            'cheque_no' => $data['cheque_no'],
            'bank_name' => $data['bank_name'],
            'cheque_date' => $data['cheque_date'],
            'amount' => $data['amount'],
            'status' => 'Pending',
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($cheque->load('creator:id,name'), 201);
    }

    public function updateStatus(Request $request, LoanPostDatedCheque $cheque)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'notes' => 'nullable|string|max:255',
            // Required when realizing — the date the cheque actually cleared
            // at the bank (check-in date), not the day someone happens to
            // mark it Realized here. This is the day the amount is
            // considered to have landed in the loan — see LoanPaymentAllocator.
            'realized_at' => 'required_if:status,Realized|nullable|date',
        ]);

        if ($data['status'] === 'Realized') {
            if ($cheque->status === 'Realized') {
                return response()->json(['message' => 'This cheque has already been realized.'], 422);
            }
            $loan = $cheque->loan;
            $asOf = Carbon::parse($data['realized_at']);
            $outstanding = LoanPaymentAllocator::outstandingBalance($loan, $asOf);
            if ((float) $cheque->amount > $outstanding) {
                return response()->json(['message' => "The outstanding balance has changed since this cheque was recorded (now {$outstanding})."], 422);
            }

            DB::transaction(function () use ($loan, $cheque, $asOf, $data, $request) {
                $branch = Branch::find($loan->branch_id);
                $code = NumberGenerator::next('payment', [
                    'branch_id' => $branch->id,
                    'branch_code' => $branch->code,
                    'type_code' => 'CQ',
                    'user_id' => $request->user()->id,
                ]);
                $payment = LoanPayment::create([
                    'code' => $code,
                    'loan_id' => $loan->id,
                    'branch_id' => $loan->branch_id,
                    'amount' => $cheque->amount,
                    'payment_method' => 'Cheque',
                    'clearance_status' => 'Realized',
                    'received_by' => $request->user()->id,
                    'paid_at' => $asOf,
                    'receipt_no' => $cheque->cheque_no,
                    'bank_name' => $cheque->bank_name,
                    'cheque_date' => $cheque->cheque_date,
                    'realized_at' => $asOf,
                    'notes' => $data['notes'] ?? $cheque->notes,
                    'is_settlement' => false,
                    'approval_status' => 'Approved',
                ]);
                NumberGenerator::attach($code, $payment);

                LoanPaymentAllocator::applyAllocation($loan, $payment, (float) $cheque->amount, $asOf);

                $cheque->update([
                    'status' => 'Realized',
                    'realized_at' => $asOf,
                    'loan_payment_id' => $payment->id,
                    'notes' => $data['notes'] ?? $cheque->notes,
                ]);
            });
        } else {
            $cheque->update([
                'status' => $data['status'],
                'handed_at' => $data['status'] === 'Handed' ? now() : $cheque->handed_at,
                'notes' => $data['notes'] ?? $cheque->notes,
            ]);
        }

        return $cheque->fresh()->load('creator:id,name');
    }

    public function destroy(LoanPostDatedCheque $cheque)
    {
        if ($cheque->status === 'Realized') {
            return response()->json(['message' => 'This cheque has already been realized and applied to the loan — void the linked payment instead of deleting the cheque record.'], 422);
        }
        $cheque->delete();

        return response()->noContent();
    }
}
