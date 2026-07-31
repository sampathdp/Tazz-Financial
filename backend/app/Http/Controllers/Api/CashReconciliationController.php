<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashReconciliation;
use App\Models\LoanPayment;
use App\Models\LoanPostDatedCheque;
use Illuminate\Http\Request;

class CashReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $query = CashReconciliation::with(['branch:id,name,code', 'reconciler:id,name'])
            ->orderByDesc('as_at_date');
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        return $query->get();
    }

    /**
     * Live system figures for a branch/date — powers the form's initial load and
     * its "Refresh" button. Also returns the already-saved entry for that date
     * (if any) so the cashier's manual fields/denomination count reload untouched.
     */
    public function summary(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'as_at_date' => 'required|date',
        ]);

        $accounts = CashAccount::where('branch_id', $data['branch_id'])->orderBy('name')->get(['id', 'name', 'current_balance']);
        $existing = CashReconciliation::with('reconciler:id,name')
            ->where('branch_id', $data['branch_id'])
            ->where('as_at_date', $data['as_at_date'])
            ->first();

        return [
            'cashAccounts' => $accounts,
            'systemCashTotal' => $this->systemCashTotal($data['branch_id']),
            'chequeInHandTotal' => $this->chequeInHandTotal($data['branch_id']),
            'postdatedChequeTotal' => $this->postdatedChequeTotal($data['branch_id']),
            'todayCashReceipts' => $this->todayCashReceipts($data['branch_id'], $data['as_at_date']),
            'todayChequeReceipts' => $this->todayChequeReceipts($data['branch_id'], $data['as_at_date']),
            'chequeRealizedTotal' => $this->chequeRealizedTotal($data['branch_id'], $data['as_at_date']),
            'existing' => $existing,
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'as_at_date' => 'required|date',
            'receipts_to_be_cancelled' => 'nullable|numeric',
            'encashment' => 'nullable|numeric',
            'cash_float' => 'nullable|numeric',
            'petty_cash' => 'nullable|numeric',
            'other_branch_cash' => 'nullable|numeric',
            'other_branch_code' => 'nullable|string|max:16',
            'other_branch_cheque' => 'nullable|numeric',
            'other_branch_cheque_code' => 'nullable|string|max:16',
            'denominations' => 'nullable|array',
            'denominations.*' => 'nullable|integer|min:0',
            'coins_amount' => 'nullable|numeric',
            'notes' => 'nullable|string|max:255',
        ]);

        $systemCashTotal = $this->systemCashTotal($data['branch_id']);
        $chequeInHandTotal = $this->chequeInHandTotal($data['branch_id']);
        $postdatedChequeTotal = $this->postdatedChequeTotal($data['branch_id']);
        $todayCashReceipts = $this->todayCashReceipts($data['branch_id'], $data['as_at_date']);
        $todayChequeReceipts = $this->todayChequeReceipts($data['branch_id'], $data['as_at_date']);
        $chequeRealizedTotal = $this->chequeRealizedTotal($data['branch_id'], $data['as_at_date']);

        $adjustments = bcadd(bcadd(bcadd(
            (string) ($data['receipts_to_be_cancelled'] ?? 0),
            (string) ($data['encashment'] ?? 0), 2),
            (string) ($data['cash_float'] ?? 0), 2),
            (string) ($data['petty_cash'] ?? 0), 2);
        $totalCash = bcadd(bcadd((string) $systemCashTotal, $adjustments, 2), (string) ($data['other_branch_cash'] ?? 0), 2);
        $totalCheque = bcadd((string) $chequeInHandTotal, (string) ($data['other_branch_cheque'] ?? 0), 2);

        $denominations = collect(CashReconciliation::DENOMINATIONS)->mapWithKeys(
            fn ($v) => [(string) $v => (int) ($data['denominations'][$v] ?? 0)]
        )->all();
        $notesTotal = collect(CashReconciliation::DENOMINATIONS)->reduce(
            fn ($carry, $v) => bcadd($carry, bcmul((string) $v, (string) $denominations[(string) $v], 2), 2), '0'
        );
        $countedTotal = bcadd($notesTotal, (string) ($data['coins_amount'] ?? 0), 2);
        $variance = bcsub($countedTotal, $totalCash, 2);

        $recon = CashReconciliation::updateOrCreate(
            ['branch_id' => $data['branch_id'], 'as_at_date' => $data['as_at_date']],
            [
                'system_cash_total' => $systemCashTotal,
                'cheque_in_hand_total' => $chequeInHandTotal,
                'postdated_cheque_total' => $postdatedChequeTotal,
                'today_cash_receipts' => $todayCashReceipts,
                'today_cheque_receipts' => $todayChequeReceipts,
                'cheque_realized_total' => $chequeRealizedTotal,
                'receipts_to_be_cancelled' => $data['receipts_to_be_cancelled'] ?? 0,
                'encashment' => $data['encashment'] ?? 0,
                'cash_float' => $data['cash_float'] ?? 0,
                'petty_cash' => $data['petty_cash'] ?? 0,
                'other_branch_cash' => $data['other_branch_cash'] ?? 0,
                'other_branch_code' => $data['other_branch_code'] ?? null,
                'other_branch_cheque' => $data['other_branch_cheque'] ?? 0,
                'other_branch_cheque_code' => $data['other_branch_cheque_code'] ?? null,
                'total_cash' => $totalCash,
                'total_cheque' => $totalCheque,
                'denominations' => $denominations,
                'coins_amount' => $data['coins_amount'] ?? 0,
                'counted_total' => $countedTotal,
                'variance' => $variance,
                'notes' => $data['notes'] ?? null,
                'reconciled_by' => $request->user()->id,
            ]
        );

        return response()->json($recon->load(['branch:id,name,code', 'reconciler:id,name']), 201);
    }

    public function destroy(CashReconciliation $cashReconciliation)
    {
        $cashReconciliation->delete();

        return response()->noContent();
    }

    private function systemCashTotal(int $branchId): string
    {
        return (string) CashAccount::where('branch_id', $branchId)->sum('current_balance');
    }

    private function chequeInHandTotal(int $branchId): string
    {
        return (string) LoanPayment::where('branch_id', $branchId)
            ->where('payment_method', 'Cheque')
            ->where('clearance_status', 'Pending')
            ->sum('amount');
    }

    private function postdatedChequeTotal(int $branchId): string
    {
        return (string) LoanPostDatedCheque::where('branch_id', $branchId)
            ->whereIn('status', ['Pending', 'Handed'])
            ->sum('amount');
    }

    /** Cash-method loan payments actually received on the given date (informational — not folded into total_cash; see the migration's comment on why). */
    private function todayCashReceipts(int $branchId, string $date): string
    {
        return (string) LoanPayment::where('branch_id', $branchId)
            ->where('payment_method', 'Cash')
            ->where('status', 'Active')
            ->whereDate('paid_at', $date)
            ->sum('amount');
    }

    /** Cheque-method loan payments received on the given date, any clearance status (informational). */
    private function todayChequeReceipts(int $branchId, string $date): string
    {
        return (string) LoanPayment::where('branch_id', $branchId)
            ->where('payment_method', 'Cheque')
            ->where('status', 'Active')
            ->whereDate('paid_at', $date)
            ->sum('amount');
    }

    /** Cheques that cleared (bank-realized) on the given date (informational). */
    private function chequeRealizedTotal(int $branchId, string $date): string
    {
        return (string) LoanPayment::where('branch_id', $branchId)
            ->where('payment_method', 'Cheque')
            ->where('clearance_status', 'Realized')
            ->where('status', 'Active')
            ->whereDate('realized_at', $date)
            ->sum('amount');
    }
}
