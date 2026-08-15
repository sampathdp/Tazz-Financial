<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashAccount;
use App\Models\CashbookEntry;
use App\Support\CurrentBranch;
use App\Support\ReportExport;
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

    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        [$title, $sections, $meta] = match ($type) {
            'position' => $this->positionPdf(),
            'cashbook-activity' => $this->cashbookActivityPdf($request),
            'bank-activity' => $this->bankActivityPdf($request),
        };

        return ReportExport::pdf($title, $sections, null, $meta)->download(ReportExport::filename($type));
    }

    private function positionPdf(): array
    {
        $d = $this->position();
        $cashRows = collect($d['cashAccounts'])->map(fn (CashAccount $a) => ['branch' => $a->branch?->name ?? '—', 'name' => $a->name, 'balance' => (float) $a->current_balance, 'status' => $a->status]);
        $bankRows = collect($d['bankAccounts'])->map(fn (BankAccount $a) => ['branch' => $a->branch?->name ?? '—', 'bank' => $a->bank?->name ?? '—', 'name' => $a->name, 'accountNo' => $a->account_number, 'balance' => (float) $a->current_balance, 'status' => $a->status]);

        $sections = [
            [
                'title' => 'Cash Accounts',
                'columns' => [['key' => 'branch', 'label' => 'Branch'], ['key' => 'name', 'label' => 'Name'], ['key' => 'balance', 'label' => 'Balance', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center']],
                'rows' => $cashRows->all(), 'totalRow' => ['name' => 'Total Cash', 'balance' => $d['totalCash']], 'emptyText' => 'No cash accounts',
            ],
            [
                'title' => 'Bank Accounts',
                'columns' => [['key' => 'branch', 'label' => 'Branch'], ['key' => 'bank', 'label' => 'Bank'], ['key' => 'name', 'label' => 'Name'], ['key' => 'accountNo', 'label' => 'Account No'], ['key' => 'balance', 'label' => 'Balance', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center']],
                'rows' => $bankRows->all(), 'totalRow' => ['name' => 'Total Bank', 'balance' => $d['totalBank']], 'emptyText' => 'No bank accounts',
            ],
            [
                'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right', 'format' => 'money']],
                'rows' => [['metric' => 'Grand Total Liquidity', 'value' => $d['grandTotal']]],
            ],
        ];

        return ['Cash & Bank Position', $sections, null];
    }

    private function cashbookActivityPdf(Request $request): array
    {
        $d = $this->cashbookActivity($request);
        $byCategory = collect($d['byCategory'])->map(fn ($v, $k) => ['category' => $k, 'in' => $v['in'], 'out' => $v['out']])->values();
        $entries = $d['entries']->map(fn (CashbookEntry $e) => [
            'date' => $e->entry_date?->format('Y-m-d') ?? '—', 'account' => $e->cashAccount?->name ?? '—', 'branch' => $e->branch?->name ?? '—',
            'direction' => $e->direction, 'category' => $e->category, 'amount' => (float) $e->amount, 'by' => $e->creator?->name ?? '—',
        ]);

        $sections = [
            [
                'title' => 'Summary',
                'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right', 'format' => 'money']],
                'rows' => [['metric' => 'Total In', 'value' => $d['totalIn']], ['metric' => 'Total Out', 'value' => $d['totalOut']]],
            ],
            [
                'title' => 'By Category',
                'columns' => [['key' => 'category', 'label' => 'Category'], ['key' => 'in', 'label' => 'In', 'align' => 'right', 'format' => 'money'], ['key' => 'out', 'label' => 'Out', 'align' => 'right', 'format' => 'money']],
                'rows' => $byCategory->all(), 'emptyText' => 'No entries in this range',
            ],
            [
                'title' => 'Entries',
                'columns' => [
                    ['key' => 'date', 'label' => 'Date'], ['key' => 'account', 'label' => 'Cash Account'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'direction', 'label' => 'Direction', 'align' => 'center'],
                    ['key' => 'category', 'label' => 'Category'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'by', 'label' => 'Created By'],
                ],
                'rows' => $entries->all(), 'emptyText' => 'No entries in this range',
            ],
        ];

        return ['Cashbook Activity', $sections, "Period: {$d['from']} to {$d['to']}"];
    }

    private function bankActivityPdf(Request $request): array
    {
        $d = $this->bankActivity($request);
        $byType = collect($d['byType'])->map(fn ($v, $k) => ['type' => $k, 'amount' => $v])->values();
        $txns = $d['transactions']->map(fn (BankTransaction $t) => [
            'date' => $t->txn_date?->format('Y-m-d') ?? '—', 'account' => $t->bankAccount?->name ?? '—', 'bank' => $t->bankAccount?->bank?->name ?? '—',
            'type' => $t->transaction_type, 'reference' => $t->reference_no ?? '—', 'amount' => (float) $t->amount, 'by' => $t->creator?->name ?? '—',
        ]);

        $sections = [
            [
                'title' => 'Summary',
                'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right', 'format' => 'money']],
                'rows' => [['metric' => 'Total Credits', 'value' => $d['totalCredits']], ['metric' => 'Total Debits', 'value' => $d['totalDebits']]],
            ],
            [
                'title' => 'By Transaction Type',
                'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']],
                'rows' => $byType->all(), 'emptyText' => 'No transactions in this range',
            ],
            [
                'title' => 'Transactions',
                'columns' => [
                    ['key' => 'date', 'label' => 'Date'], ['key' => 'account', 'label' => 'Account'], ['key' => 'bank', 'label' => 'Bank'], ['key' => 'type', 'label' => 'Type'],
                    ['key' => 'reference', 'label' => 'Reference'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'by', 'label' => 'Created By'],
                ],
                'rows' => $txns->all(), 'emptyText' => 'No transactions in this range',
            ],
        ];

        return ['Bank Activity', $sections, "Period: {$d['from']} to {$d['to']}"];
    }

    /** Current balance of every cash till and bank account — the "how much liquidity do we have right now" view. */
    private function position(): array
    {
        $cash = CurrentBranch::applyScope(CashAccount::with('branch:id,name,code'))->orderBy('name')->get(['id', 'branch_id', 'name', 'current_balance', 'status']);
        $bank = CurrentBranch::applyScope(BankAccount::with(['branch:id,name,code', 'bank:id,name']))->orderBy('name')->get(['id', 'branch_id', 'bank_id', 'name', 'account_number', 'current_balance', 'status']);

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

        $entries = CurrentBranch::applyScope(CashbookEntry::with(['cashAccount:id,name', 'branch:id,name,code', 'creator:id,name'])
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->query('cashAccountId'), fn ($q, $id) => $q->where('cash_account_id', $id)))
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
            // bank_transactions has no branch_id of its own — scope via its bank account's branch.
            ->whereHas('bankAccount', fn ($q) => CurrentBranch::applyScope($q))
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
