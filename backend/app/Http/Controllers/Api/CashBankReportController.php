<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashAccount;
use App\Models\CashbookEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Cash & Bank reports (Reports > Cash & Bank Reports). Same type-dispatch
 * shape as the other *ReportController classes, reading straight off
 * cash_accounts/bank_accounts/cashbook_entries/bank_transactions.
 */
class CashBankReportController extends Controller
{
    private const TYPES = ['position', 'cashbook-activity', 'bank-activity'];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'position' => $this->position(),
            'cashbook-activity' => $this->cashbookActivity($request),
            'bank-activity' => $this->bankActivity($request),
        };
    }

    /** Current balance of every cash till and bank account — the "how much liquidity do we have right now" view. */
    private function position(): array
    {
        $cash = CashAccount::with('branch:id,name,code')->orderBy('name')->get(['id', 'branch_id', 'name', 'current_balance', 'status']);
        $bank = BankAccount::with(['branch:id,name,code', 'bank:id,name'])->orderBy('name')->get(['id', 'branch_id', 'bank_id', 'name', 'account_number', 'current_balance', 'status']);

        return [
            'cashAccounts' => $cash,
            'bankAccounts' => $bank,
            'totalCash' => round((float) $cash->where('status', 'Active')->sum('current_balance'), 2),
            'totalBank' => round((float) $bank->where('status', 'Active')->sum('current_balance'), 2),
            'grandTotal' => round((float) $cash->where('status', 'Active')->sum('current_balance') + (float) $bank->where('status', 'Active')->sum('current_balance'), 2),
        ];
    }

    private function cashbookActivity(Request $request): array
    {
        [$from, $to] = $this->periodBounds($request);

        $entries = CashbookEntry::with(['cashAccount:id,name', 'branch:id,name,code', 'creator:id,name'])
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->query('cashAccountId'), fn ($q, $id) => $q->where('cash_account_id', $id))
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->get();

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'totalIn' => round((float) $entries->where('direction', 'In')->sum('amount'), 2),
            'totalOut' => round((float) $entries->where('direction', 'Out')->sum('amount'), 2),
            'byCategory' => $entries->groupBy('category')->map(fn ($g) => [
                'in' => round((float) $g->where('direction', 'In')->sum('amount'), 2),
                'out' => round((float) $g->where('direction', 'Out')->sum('amount'), 2),
            ]),
            'entries' => $entries,
        ];
    }

    private function bankActivity(Request $request): array
    {
        [$from, $to] = $this->periodBounds($request);

        $txns = BankTransaction::with(['bankAccount:id,name,bank_id', 'bankAccount.bank:id,name', 'creator:id,name'])
            ->whereBetween('txn_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->query('bankAccountId'), fn ($q, $id) => $q->where('bank_account_id', $id))
            ->orderByDesc('txn_date')->orderByDesc('id')
            ->get();

        $credits = $txns->whereIn('transaction_type', BankTransaction::CREDIT_TYPES);
        $debits = $txns->whereNotIn('transaction_type', BankTransaction::CREDIT_TYPES);

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'totalCredits' => round((float) $credits->sum('amount'), 2),
            'totalDebits' => round((float) $debits->sum('amount'), 2),
            'byType' => $txns->groupBy('transaction_type')->map(fn ($g) => round((float) $g->sum('amount'), 2)),
            'transactions' => $txns,
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodBounds(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }
}
