<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FacilityPayment;
use App\Models\FacilityPostDatedCheque;
use App\Models\Loan;
use App\Services\NumberGenerator;
use App\Support\LoanLedgerPoster;
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
        return $loan->postDatedCheques()->with(['creator:id,name', 'bank:id,name'])->get();
    }

    /**
     * Flat, cross-loan list for the Cheque Management screen — every cheque the
     * user's branch scope can see. Merges two sources:
     *   - FacilityPostDatedCheque: cheques handed over for future collection, with
     *     a real Pending/Handed/Realized/Bounced/Returned workflow.
     *   - FacilityPayment rows recorded with paymentMethod=Cheque: held Pending
     *     (not applied to the balance) until manually cleared via
     *     LoanPaymentController::clearCheque() — see PaymentReceipt.tsx's
     *     "Cheque" payment method vs. "Record Cheque" (Post Dated Cheques).
     *     Settlement cheques are excluded while awaiting their own approval;
     *     once approved (or for a plain Cheque payment once realized) they
     *     read here as Realized, same as any other cleared cheque.
     */
    public function all(Request $request)
    {
        $status = $request->filled('status') ? (string) $request->input('status') : null;
        $dueToday = $request->boolean('due_today');
        $fromDate = $request->filled('from_date') ? (string) $request->input('from_date') : null;
        $toDate = $request->filled('to_date') ? (string) $request->input('to_date') : null;
        $today = now()->toDateString();

        $postDated = FacilityPostDatedCheque::with([
            'facility:id,code,customer_id,branch_id',
            'facility.customer:id,full_name',
            'facility.branch:id,name,code',
            'creator:id,name',
            'bank:id,name',
        ])
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($dueToday, fn ($q) => $q->whereDate('cheque_date', $today))
            // Effective date for From/To is realized_at once cleared, cheque_date
            // otherwise — realized_at is only ever set together with
            // status='Realized' (see updateStatus() below), so COALESCE picks
            // the same date the old PHP filter did.
            ->when($fromDate, fn ($q, $d) => $q->whereRaw('COALESCE(realized_at, cheque_date) >= ?', [$d]))
            ->when($toDate, fn ($q, $d) => $q->whereRaw('COALESCE(realized_at, cheque_date) <= ?', [$d]))
            ->get()->map(fn (FacilityPostDatedCheque $c) => [
            'id' => $c->id,
            'source' => 'PostDated',
            'cheque_no' => $c->cheque_no,
            'bank_name' => $c->bank?->name,
            'cheque_date' => optional($c->cheque_date)->toDateString(),
            'realized_at' => optional($c->realized_at)->toDateString(),
            'amount' => $c->amount,
            'status' => $c->status,
            'loan' => $this->loanSummary($c->facility),
            'creator' => $c->creator ? ['id' => $c->creator->id, 'name' => $c->creator->name] : null,
        ]);

        $payments = FacilityPayment::with([
            'facility:id,code,customer_id,branch_id',
            'facility.customer:id,full_name',
            'facility.branch:id,name,code',
            'receiver:id,name',
            'bank:id,name',
        ])
            ->where('payment_method', 'Cheque')
            ->where('approval_status', '!=', 'PendingApproval')
            // A cheque realized from the Post Dated Cheques flow already
            // appears via $postDated above (with its own facility_payment_id
            // link) — excluding it here keeps it from listing twice.
            ->whereDoesntHave('postDatedCheque')
            // Keep live payments plus ones voided specifically because the cheque
            // bounced (so its rejection still shows up in the history); drop
            // payments voided for any other reason (a cancelled receipt entirely).
            ->where(fn ($q) => $q->where('status', '!=', 'Voided')->orWhere('clearance_status', 'Bounced'))
            // clearance_status is null for an approved settlement cheque, which
            // reads as 'Realized' below — match that same fallback in SQL.
            ->when($status, fn ($q, $s) => $s === 'Realized' ? $q->where(fn ($qq) => $qq->where('clearance_status', 'Realized')->orWhereNull('clearance_status')) : $q->where('clearance_status', $s))
            ->when($dueToday, fn ($q) => $q->whereDate('cheque_date', $today))
            ->when($fromDate, fn ($q, $d) => $q->whereRaw('COALESCE(realized_at, cheque_date) >= ?', [$d]))
            ->when($toDate, fn ($q, $d) => $q->whereRaw('COALESCE(realized_at, cheque_date) <= ?', [$d]))
            ->get()->map(fn (FacilityPayment $p) => [
                'id' => $p->id,
                'source' => 'Payment',
                'cheque_no' => $p->receipt_no,
                'bank_name' => $p->bank?->name,
                'cheque_date' => optional($p->cheque_date)->toDateString(),
                'realized_at' => optional($p->realized_at)->toDateString(),
                'amount' => $p->amount,
                // A plain cheque payment carries its own Pending/Realized/Bounced
                // clearance_status; an approved settlement cheque has none (its
                // approval already implies it applied), so it reads as Realized.
                'status' => $p->clearance_status ?? 'Realized',
                'loan' => $this->loanSummary($p->facility),
                'creator' => $p->receiver ? ['id' => $p->receiver->id, 'name' => $p->receiver->name] : null,
            ]);

        $all = $postDated->concat($payments);
        if ($status) {
            $all = $all->where('status', $status);
        }
        if ($dueToday) {
            $all = $all->where('cheque_date', $today);
        }
        // Once a cheque has cleared, the meaningful date for a From/To search is
        // when it was actually realized at the bank, not its (possibly long past)
        // cheque date — e.g. a cheque dated in January realized in April should
        // show up when filtering for April. Open/bounced cheques have no
        // realized_at, so they fall back to the cheque date.
        if ($fromDate || $toDate) {
            $all = $all->filter(function ($c) use ($fromDate, $toDate) {
                $effective = $c['status'] === 'Realized' && $c['realized_at'] ? $c['realized_at'] : $c['cheque_date'];
                if (!$effective) {
                    return false;
                }
                if ($fromDate && $effective < $fromDate) {
                    return false;
                }
                if ($toDate && $effective > $toDate) {
                    return false;
                }

                return true;
            });
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
            'cheque_no' => 'required|string|max:20',
            'bank_id' => 'required|exists:banks,id',
            'cheque_date' => 'required|date',
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'notes' => 'nullable|string|max:255',
        ]);

        $cheque = $loan->postDatedCheques()->create([
            'branch_id' => $loan->branch_id,
            'cheque_no' => $data['cheque_no'],
            'bank_id' => $data['bank_id'],
            'cheque_date' => $data['cheque_date'],
            'amount' => $data['amount'],
            'status' => 'Pending',
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($cheque->load(['creator:id,name', 'bank:id,name']), 201);
    }

    public function updateStatus(Request $request, FacilityPostDatedCheque $cheque)
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
            $loan = Loan::findOrFail($cheque->credit_facility_id);
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
                $payment = FacilityPayment::create([
                    'code' => $code,
                    'credit_facility_id' => $loan->id,
                    'branch_id' => $loan->branch_id,
                    'amount' => $cheque->amount,
                    'payment_method' => 'Cheque',
                    'payment_type' => 'Regular',
                    'clearance_status' => 'Realized',
                    'received_by' => $request->user()->id,
                    'paid_at' => $asOf,
                    'receipt_no' => $cheque->cheque_no,
                    'bank_id' => $cheque->bank_id,
                    'cheque_date' => $cheque->cheque_date,
                    'realized_at' => $asOf,
                    'notes' => $data['notes'] ?? $cheque->notes,
                    'approval_status' => 'Approved',
                ]);
                NumberGenerator::attach($code, $payment);

                LoanPaymentAllocator::applyAllocation($loan, $payment, (float) $cheque->amount, $asOf);
                LoanLedgerPoster::postChequeRealized($payment);

                $cheque->update([
                    'status' => 'Realized',
                    'realized_at' => $asOf,
                    'facility_payment_id' => $payment->id,
                    'notes' => $data['notes'] ?? $cheque->notes,
                ]);
            });
        } elseif ($cheque->status === 'Realized') {
            // Already cleared and applied to the loan: the only way out is the bank sending it back. That
            // takes back what it paid — the schedule/charge allocation and the "Cheque realized" entry —
            // exactly like cancelling the linked receipt; relabelling it alone would leave the loan paid.
            if ($data['status'] !== 'Bounced') {
                return response()->json(['message' => 'This cheque has already been realized and applied to the loan — it can only be marked Bounced (which reverses the payment).'], 422);
            }
            DB::transaction(function () use ($cheque, $data, $request) {
                Loan::whereKey($cheque->credit_facility_id)->lockForUpdate()->first();
                $payment = $cheque->facility_payment_id ? FacilityPayment::find($cheque->facility_payment_id) : null;
                if ($payment && $payment->status !== 'Voided') {
                    $payment->update([
                        'clearance_status' => 'Bounced',
                        'status' => 'Voided',
                        'void_reason' => 'Cheque bounced',
                        'voided_by' => $request->user()->id,
                        'voided_at' => now(),
                    ]);
                    LoanPaymentAllocator::reverseAllocation($payment);
                    LoanLedgerPoster::reverseAllFor($payment, $request->user()->id);
                }
                $cheque->update(['status' => 'Bounced', 'notes' => $data['notes'] ?? $cheque->notes]);
            });
        } else {
            $cheque->update([
                'status' => $data['status'],
                'handed_at' => $data['status'] === 'Handed' ? now() : $cheque->handed_at,
                'notes' => $data['notes'] ?? $cheque->notes,
            ]);
        }

        return $cheque->fresh()->load(['creator:id,name', 'bank:id,name']);
    }

    public function destroy(FacilityPostDatedCheque $cheque)
    {
        if ($cheque->status === 'Realized') {
            return response()->json(['message' => 'This cheque has already been realized and applied to the loan — void the linked payment instead of deleting the cheque record.'], 422);
        }
        $cheque->delete();

        return response()->noContent();
    }
}
