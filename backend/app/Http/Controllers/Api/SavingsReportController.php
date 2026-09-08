<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavingsAccount;
use App\Models\SavingsCollectionSheet;
use App\Models\SavingsInterestRun;
use App\Models\SavingsTransaction;
use App\Support\CurrentBranch;
use App\Support\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Daily Savings reports (Reports > Savings Reports). Same type-dispatch shape
 * as FundingReportController.
 */
class SavingsReportController extends Controller
{
    private const TYPES = [
        'active-accounts', 'dormant-accounts', 'balance-summary', 'transaction-history',
        'collection-sheets', 'interest-posted', 'account-statement', 'new-accounts',
        'closed-accounts', 'below-minimum',
    ];

    private const TITLES = [
        'active-accounts' => 'Active Savings Accounts',
        'dormant-accounts' => 'Dormant Savings Accounts',
        'balance-summary' => 'Savings Balance Summary by Product',
        'transaction-history' => 'Savings Transaction History',
        'collection-sheets' => 'Savings Collection Sheets',
        'interest-posted' => 'Savings Interest Posted',
        'account-statement' => 'Savings Account Statement',
        'new-accounts' => 'New Savings Accounts',
        'closed-accounts' => 'Closed Savings Accounts',
        'below-minimum' => 'Savings Accounts Below Minimum Balance',
    ];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'active-accounts' => $this->accounts($request, 'Active'),
            'dormant-accounts' => $this->accounts($request, 'Dormant'),
            'closed-accounts' => $this->closed($request),
            'new-accounts' => $this->newAccounts($request),
            'below-minimum' => $this->belowMinimum($request),
            'balance-summary' => $this->balanceSummary(),
            'transaction-history' => $this->transactions($request),
            'collection-sheets' => $this->collectionSheets($request),
            'interest-posted' => $this->interestPosted($request),
            'account-statement' => $this->accountStatement($request),
        };
    }

    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }
        $data = $this->show($request, $type);

        $sections = match ($type) {
            'active-accounts', 'dormant-accounts', 'new-accounts', 'below-minimum' => [$this->accountSection(collect($data))],
            'closed-accounts' => [$this->closedSection(collect($data))],
            'balance-summary' => [$this->balanceSummarySection(collect($data))],
            'transaction-history', 'account-statement' => [$this->txnSection(collect(is_array($data) && isset($data['transactions']) ? $data['transactions'] : $data))],
            'collection-sheets' => [$this->collectionSection(collect($data))],
            'interest-posted' => [$this->interestSection(collect($data))],
        };

        return ReportExport::pdf(self::TITLES[$type], $sections, null, null)->download(ReportExport::filename($type));
    }

    // ---- data ----

    private function accounts(Request $request, string $status)
    {
        return CurrentBranch::applyScope(
            SavingsAccount::with(['customer:id,full_name,code', 'savingsProduct:id,name,code', 'branch:id,name,code'])->where('status', $status)
        )->orderByDesc('current_balance')->get();
    }

    private function closed(Request $request)
    {
        $q = CurrentBranch::applyScope(SavingsAccount::with(['customer:id,full_name,code', 'savingsProduct:id,name,code'])->where('status', 'Closed'));
        if ($from = $request->query('from')) {
            $q->whereDate('closed_on', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('closed_on', '<=', $to);
        }

        return $q->orderByDesc('closed_on')->get();
    }

    private function newAccounts(Request $request)
    {
        $q = CurrentBranch::applyScope(SavingsAccount::with(['customer:id,full_name,code', 'savingsProduct:id,name,code']));
        if ($from = $request->query('from')) {
            $q->whereDate('open_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('open_date', '<=', $to);
        }

        return $q->orderByDesc('open_date')->get();
    }

    private function belowMinimum(Request $request)
    {
        return CurrentBranch::applyScope(
            SavingsAccount::with(['customer:id,full_name,code', 'savingsProduct:id,name,code'])
                ->where('status', 'Active')
                ->whereColumn('current_balance', '<', 'min_balance')
        )->orderBy('current_balance')->get();
    }

    private function balanceSummary()
    {
        $rows = CurrentBranch::applyScope(SavingsAccount::query())
            ->selectRaw('savings_product_id, count(*) as account_count')
            ->selectRaw("sum(case when status != 'Closed' then 1 else 0 end) as open_count")
            ->selectRaw("sum(case when status != 'Closed' then current_balance else 0 end) as total_balance")
            ->groupBy('savings_product_id')
            ->with('savingsProduct:id,name,code')
            ->get()
            ->map(fn ($r) => [
                'product' => $r->savingsProduct?->name ?? '—',
                'code' => $r->savingsProduct?->code ?? '—',
                'account_count' => (int) $r->account_count,
                'open_count' => (int) $r->open_count,
                'total_balance' => round((float) $r->total_balance, 2),
            ])->values();

        return $rows;
    }

    private function transactions(Request $request)
    {
        $q = SavingsTransaction::with(['savingsAccount:id,account_number,customer_id,branch_id', 'savingsAccount.customer:id,full_name,code'])
            ->whereHas('savingsAccount', fn ($a) => CurrentBranch::applyScope($a));
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('txn_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('txn_date', '<=', $to);
        }
        if ($request->boolean('postedOnly', true)) {
            $q->where('status', 'Posted');
        }

        return $q->orderByDesc('txn_date')->orderByDesc('id')->get();
    }

    private function collectionSheets(Request $request)
    {
        $q = CurrentBranch::applyScope(SavingsCollectionSheet::with(['branch:id,name,code', 'collector:id,name'])->withCount('lines'));
        if ($from = $request->query('from')) {
            $q->whereDate('collection_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('collection_date', '<=', $to);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return $q->orderByDesc('collection_date')->get();
    }

    private function interestPosted(Request $request)
    {
        $q = SavingsInterestRun::with(['branch:id,name,code', 'poster:id,name'])->where('status', 'Posted');
        if ($from = $request->query('from')) {
            $q->whereDate('period_to', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('period_to', '<=', $to);
        }

        return $q->orderByDesc('period_to')->get();
    }

    private function accountStatement(Request $request)
    {
        $accountId = $request->query('accountId');
        if (!$accountId) {
            throw ValidationException::withMessages(['accountId' => 'accountId is required for this report.']);
        }
        $account = SavingsAccount::with(['customer:id,full_name,code,nic_no', 'savingsProduct:id,name,code', 'branch:id,name,code'])->findOrFail($accountId);
        $txns = $account->transactions()->with('bank:id,name')
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('txn_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('txn_date', '<=', $d))
            ->orderBy('txn_date')->orderBy('id')->get();

        return ['account' => $account, 'transactions' => $txns];
    }

    // ---- pdf sections ----

    private function accountSection(Collection $rows): array
    {
        $mapped = $rows->map(fn (SavingsAccount $a) => [
            'acctNo' => $a->account_number, 'customer' => $a->customer?->full_name ?? '—', 'product' => $a->savingsProduct?->name ?? '—',
            'opened' => $a->open_date?->format('Y-m-d') ?? '—', 'balance' => (float) $a->current_balance, 'status' => $a->status,
        ]);

        return [
            'columns' => [
                ['key' => 'acctNo', 'label' => 'Account No'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'product', 'label' => 'Product'],
                ['key' => 'opened', 'label' => 'Opened'], ['key' => 'balance', 'label' => 'Balance', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
            ],
            'rows' => $mapped->all(), 'totalRow' => ['customer' => 'Total', 'balance' => $mapped->sum('balance')], 'emptyText' => 'No accounts',
        ];
    }

    private function closedSection(Collection $rows): array
    {
        $mapped = $rows->map(fn (SavingsAccount $a) => [
            'acctNo' => $a->account_number, 'customer' => $a->customer?->full_name ?? '—', 'product' => $a->savingsProduct?->name ?? '—',
            'opened' => $a->open_date?->format('Y-m-d') ?? '—', 'closed' => $a->closed_on?->format('Y-m-d') ?? '—',
        ]);

        return [
            'columns' => [
                ['key' => 'acctNo', 'label' => 'Account No'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'product', 'label' => 'Product'],
                ['key' => 'opened', 'label' => 'Opened'], ['key' => 'closed', 'label' => 'Closed'],
            ],
            'rows' => $mapped->all(), 'emptyText' => 'No accounts',
        ];
    }

    private function balanceSummarySection(Collection $rows): array
    {
        return [
            'columns' => [
                ['key' => 'product', 'label' => 'Product'], ['key' => 'code', 'label' => 'Code'],
                ['key' => 'account_count', 'label' => 'Accounts', 'align' => 'right', 'format' => 'number'],
                ['key' => 'open_count', 'label' => 'Open', 'align' => 'right', 'format' => 'number'],
                ['key' => 'total_balance', 'label' => 'Total Balance', 'align' => 'right', 'format' => 'money'],
            ],
            'rows' => $rows->all(),
            'totalRow' => ['product' => 'Total', 'account_count' => $rows->sum('account_count'), 'open_count' => $rows->sum('open_count'), 'total_balance' => $rows->sum('total_balance')],
            'emptyText' => 'No accounts',
        ];
    }

    private function txnSection(Collection $rows): array
    {
        $mapped = $rows->map(fn (SavingsTransaction $t) => [
            'txnNo' => $t->txn_number, 'account' => $t->savingsAccount?->account_number ?? '—', 'date' => $t->txn_date?->format('Y-m-d') ?? '—',
            'type' => $t->type, 'in' => $t->direction === 'In' ? (float) $t->amount : 0.0, 'out' => $t->direction === 'Out' ? (float) $t->amount : 0.0,
            'balance' => (float) $t->running_balance, 'status' => $t->status,
        ]);

        return [
            'columns' => [
                ['key' => 'txnNo', 'label' => 'Txn No'], ['key' => 'account', 'label' => 'Account'], ['key' => 'date', 'label' => 'Date'], ['key' => 'type', 'label' => 'Type'],
                ['key' => 'in', 'label' => 'In', 'align' => 'right', 'format' => 'money'], ['key' => 'out', 'label' => 'Out', 'align' => 'right', 'format' => 'money'],
                ['key' => 'balance', 'label' => 'Balance', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
            ],
            'rows' => $mapped->all(),
            'totalRow' => ['type' => 'Total', 'in' => $mapped->sum('in'), 'out' => $mapped->sum('out')],
            'emptyText' => 'No transactions',
        ];
    }

    private function collectionSection(Collection $rows): array
    {
        $mapped = $rows->map(fn (SavingsCollectionSheet $s) => [
            'sheetNo' => $s->sheet_number, 'date' => $s->collection_date?->format('Y-m-d') ?? '—', 'collector' => $s->collector?->name ?? '—',
            'lines' => (int) ($s->lines_count ?? $s->line_count), 'total' => (float) $s->total_amount, 'status' => $s->status,
        ]);

        return [
            'columns' => [
                ['key' => 'sheetNo', 'label' => 'Sheet No'], ['key' => 'date', 'label' => 'Date'], ['key' => 'collector', 'label' => 'Collector'],
                ['key' => 'lines', 'label' => 'Lines', 'align' => 'right', 'format' => 'number'], ['key' => 'total', 'label' => 'Total', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
            ],
            'rows' => $mapped->all(), 'totalRow' => ['collector' => 'Total', 'total' => $mapped->sum('total')], 'emptyText' => 'No sheets',
        ];
    }

    private function interestSection(Collection $rows): array
    {
        $mapped = $rows->map(fn (SavingsInterestRun $r) => [
            'runNo' => $r->run_number, 'from' => $r->period_from?->format('Y-m-d') ?? '—', 'to' => $r->period_to?->format('Y-m-d') ?? '—',
            'accounts' => (int) $r->account_count, 'interest' => (float) $r->total_interest, 'postedAt' => $r->posted_at?->format('Y-m-d') ?? '—',
        ]);

        return [
            'columns' => [
                ['key' => 'runNo', 'label' => 'Run No'], ['key' => 'from', 'label' => 'From'], ['key' => 'to', 'label' => 'To'],
                ['key' => 'accounts', 'label' => 'Accounts', 'align' => 'right', 'format' => 'number'], ['key' => 'interest', 'label' => 'Interest', 'align' => 'right', 'format' => 'money'], ['key' => 'postedAt', 'label' => 'Posted'],
            ],
            'rows' => $mapped->all(), 'totalRow' => ['to' => 'Total', 'interest' => $mapped->sum('interest')], 'emptyText' => 'No runs',
        ];
    }
}
