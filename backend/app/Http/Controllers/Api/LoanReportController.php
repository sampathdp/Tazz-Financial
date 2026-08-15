<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Support\LoanSummary;
use App\Support\ReportExport;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Loan portfolio reports (Reports > Loan Reports). Same type-dispatch shape
 * as FundingReportController/AccountingReportController: one route, an
 * allow-listed TYPES const, one private method per type, everything computed
 * live off credit_facilities/facility_schedules — nothing persisted here.
 */
class LoanReportController extends Controller
{
    private const TYPES = ['portfolio-summary', 'arrears', 'par-aging', 'disbursement', 'closed-loans'];

    private const LIST_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone',
        'branch:id,name,code',
        'loanDetail.loanType:id,name,code',
        'loanDetail.loanOfficer:id,name',
    ];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'portfolio-summary' => $this->portfolioSummary(),
            'arrears' => $this->arrears($request),
            'par-aging' => $this->parAging(),
            'disbursement' => $this->disbursement($request),
            'closed-loans' => $this->closedLoans($request),
        };
    }

    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        [$title, $sections, $meta] = match ($type) {
            'portfolio-summary' => $this->portfolioSummaryPdf(),
            'arrears' => $this->arrearsPdf($request),
            'par-aging' => $this->parAgingPdf(),
            'disbursement' => $this->disbursementPdf($request),
            'closed-loans' => $this->closedLoansPdf($request),
        };

        return ReportExport::pdf($title, $sections, null, $meta)->download(ReportExport::filename($type));
    }

    private function portfolioSummaryPdf(): array
    {
        $d = $this->portfolioSummary();
        $rows = [
            ['metric' => 'Total Loans', 'value' => $d['totalLoans']],
            ['metric' => 'Active Loans', 'value' => $d['activeLoans']],
            ['metric' => 'Closed Loans', 'value' => $d['closedLoans']],
            ['metric' => 'Pending Loans', 'value' => $d['pendingLoans']],
            ['metric' => 'Written Off Loans', 'value' => $d['writtenOffLoans']],
            ['metric' => 'Total Outstanding Balance', 'value' => $d['totalOutstandingBalance'], 'format' => 'money'],
            ['metric' => 'Average Loan Size', 'value' => $d['avgLoanSize'], 'format' => 'money'],
            ['metric' => 'Disbursed This Month (count)', 'value' => $d['disbursedThisMonthCount']],
            ['metric' => 'Disbursed This Month (amount)', 'value' => $d['disbursedThisMonthAmount'], 'format' => 'money'],
            ['metric' => 'Disbursed Last Month (amount)', 'value' => $d['disbursedLastMonthAmount'], 'format' => 'money'],
            ['metric' => 'Loan Growth %', 'value' => $d['loanGrowthPct'], 'format' => 'percent'],
        ];
        $sections = [['columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']], 'rows' => $this->formattedMetricRows($rows)]];

        return ['Loan Portfolio Summary', $sections, 'As of ' . $d['asOf']];
    }

    private function parAgingPdf(): array
    {
        $d = $this->parAging();
        $summaryRows = $this->formattedMetricRows([
            ['metric' => 'Total Active Portfolio', 'value' => $d['totalPortfolio'], 'format' => 'money'],
            ['metric' => 'PAR 30 %', 'value' => $d['par30Pct'], 'format' => 'percent'],
            ['metric' => 'PAR 60 %', 'value' => $d['par60Pct'], 'format' => 'percent'],
            ['metric' => 'NPL % (90+ days)', 'value' => $d['nplPct'], 'format' => 'percent'],
            ['metric' => 'NPL Amount', 'value' => $d['nplAmount'], 'format' => 'money'],
        ]);

        $sections = [
            ['title' => 'Summary', 'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']], 'rows' => $summaryRows],
            [
                'title' => 'Aging Buckets',
                'columns' => [
                    ['key' => 'label', 'label' => 'Bucket'], ['key' => 'count', 'label' => 'Loans', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'amount', 'label' => 'Outstanding', 'align' => 'right', 'format' => 'money'], ['key' => 'pct', 'label' => '% of Portfolio', 'align' => 'right', 'format' => 'percent'],
                ],
                'rows' => $d['buckets'],
            ],
        ];

        return ['Loan PAR / NPL Aging', $sections, 'As of ' . $d['asOf']];
    }

    private function arrearsPdf(Request $request): array
    {
        $rows = $this->arrears($request);
        $columns = [
            ['key' => 'code', 'label' => 'Loan Code'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'officer', 'label' => 'Officer'],
            ['key' => 'daysOverdue', 'label' => 'Days Overdue', 'align' => 'right', 'format' => 'number'], ['key' => 'installments', 'label' => 'Installments', 'align' => 'right', 'format' => 'number'],
            ['key' => 'overdueAmount', 'label' => 'Overdue Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'penaltyDue', 'label' => 'Penalty Due', 'align' => 'right', 'format' => 'money'],
        ];
        $mapped = collect($rows)->map(fn ($r) => [
            'code' => $r['code'] ?? '', 'customer' => $r['customer']['full_name'] ?? '—', 'branch' => $r['branch']['name'] ?? '—',
            'officer' => $r['loan_detail']['recovery_officer']['name'] ?? $r['loan_detail']['loan_officer']['name'] ?? '—',
            'daysOverdue' => $r['daysOverdue'] ?? 0, 'installments' => $r['overdueInstallments'] ?? 0,
            'overdueAmount' => $r['overdueAmount'] ?? 0, 'penaltyDue' => $r['penaltyDue'] ?? 0,
        ]);
        $totalRow = ['customer' => 'Total', 'overdueAmount' => $mapped->sum('overdueAmount'), 'penaltyDue' => $mapped->sum('penaltyDue')];
        $sections = [['columns' => $columns, 'rows' => $mapped->all(), 'totalRow' => $totalRow, 'emptyText' => 'No overdue loans']];

        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : now()->toDateString();
        $meta = $request->filled('from') ? 'As of ' . $to . ' (from ' . Carbon::parse($request->from)->toDateString() . ')' : 'As of ' . $to;

        return ['Loan Arrears', $sections, $meta];
    }

    private function disbursementPdf(Request $request): array
    {
        $loans = $this->disbursement($request);
        $columns = [
            ['key' => 'code', 'label' => 'Loan Code'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'loanType', 'label' => 'Loan Type'],
            ['key' => 'officer', 'label' => 'Officer'], ['key' => 'principal', 'label' => 'Principal', 'align' => 'right', 'format' => 'money'],
            ['key' => 'disbursed', 'label' => 'Disbursed'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
        ];
        $mapped = $loans->map(fn (Loan $l) => [
            'code' => $l->code, 'customer' => $l->customer?->full_name ?? '—', 'branch' => $l->branch?->name ?? '—',
            'loanType' => $l->loanDetail?->loanType?->name ?? '—', 'officer' => $l->loanDetail?->loanOfficer?->name ?? '—',
            'principal' => (float) $l->principal_amount, 'disbursed' => $l->disbursed_at?->format('Y-m-d') ?? '—', 'status' => $l->status,
        ]);
        $totalRow = ['customer' => 'Total', 'principal' => $mapped->sum('principal')];
        $sections = [['columns' => $columns, 'rows' => $mapped->all(), 'totalRow' => $totalRow, 'emptyText' => 'No disbursements in this range']];

        return ['Loan Disbursement', $sections, $this->periodMeta($request)];
    }

    private function closedLoansPdf(Request $request): array
    {
        $loans = $this->closedLoans($request);
        $columns = [
            ['key' => 'code', 'label' => 'Loan Code'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'loanType', 'label' => 'Loan Type'], ['key' => 'principal', 'label' => 'Principal', 'align' => 'right', 'format' => 'money'], ['key' => 'closed', 'label' => 'Closed'],
        ];
        $mapped = $loans->map(fn (Loan $l) => [
            'code' => $l->code, 'customer' => $l->customer?->full_name ?? '—', 'branch' => $l->branch?->name ?? '—',
            'loanType' => $l->loanDetail?->loanType?->name ?? '—', 'principal' => (float) $l->principal_amount, 'closed' => $l->closed_at?->format('Y-m-d') ?? '—',
        ]);
        $totalRow = ['customer' => 'Total', 'principal' => $mapped->sum('principal')];
        $sections = [['columns' => $columns, 'rows' => $mapped->all(), 'totalRow' => $totalRow, 'emptyText' => 'No closed loans in this range']];

        return ['Closed Loans', $sections, $this->periodMeta($request)];
    }

    /** metric/value rows -> ['metric','value','format'] shape the generic view expects per-cell, but format is per-column not per-row, so fold it in as a formatted string here instead. */
    private function formattedMetricRows(array $rows): array
    {
        return array_map(function ($r) {
            $value = $r['value'];
            $formatted = match ($r['format'] ?? null) {
                'money' => number_format((float) $value, 2),
                'percent' => $value === null ? '—' : number_format((float) $value, 2) . '%',
                default => (string) $value,
            };

            return ['metric' => $r['metric'], 'value' => $formatted];
        }, $rows);
    }

    private function periodMeta(Request $request): string
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : now()->startOfMonth()->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : now()->toDateString();

        return "Period: {$from} to {$to}";
    }

    /** Loan Portfolio Ratios source data: counts, outstanding, avg size, disbursement growth. */
    private function portfolioSummary(): array
    {
        $counts = Loan::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $active = Loan::where('status', 'Active');
        $totalOutstanding = (clone $active)->sum('outstanding_balance_cache');
        $activeCount = (clone $active)->count();

        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = now()->startOfMonth();

        $thisMonth = Loan::whereNotNull('disbursed_at')->where('disbursed_at', '>=', $monthStart);
        $lastMonth = Loan::whereNotNull('disbursed_at')->whereBetween('disbursed_at', [$lastMonthStart, $lastMonthEnd]);
        $disbursedThisMonth = (clone $thisMonth)->sum('disbursed_amount');
        $disbursedLastMonth = (clone $lastMonth)->sum('disbursed_amount');

        return [
            'asOf' => now()->toDateString(),
            'totalLoans' => (int) $counts->sum(),
            'activeLoans' => $activeCount,
            'closedLoans' => (int) ($counts['Closed'] ?? 0),
            'pendingLoans' => (int) ($counts['Pending'] ?? 0),
            'writtenOffLoans' => (int) ($counts['WrittenOff'] ?? 0),
            'totalOutstandingBalance' => round((float) $totalOutstanding, 2),
            'avgLoanSize' => $activeCount > 0 ? round((float) $totalOutstanding / $activeCount, 2) : 0,
            'disbursedThisMonthCount' => (clone $thisMonth)->count(),
            'disbursedThisMonthAmount' => round((float) $disbursedThisMonth, 2),
            'disbursedLastMonthAmount' => round((float) $disbursedLastMonth, 2),
            'loanGrowthPct' => $disbursedLastMonth > 0
                ? round((((float) $disbursedThisMonth - (float) $disbursedLastMonth) / (float) $disbursedLastMonth) * 100, 2)
                : null,
        ];
    }

    private function arrears(Request $request)
    {
        $today = $request->filled('to') ? Carbon::parse($request->to)->startOfDay() : now()->startOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;

        return LoanSummary::overdueList($today, $from);
    }

    /** PAR 30/60/90 aging buckets + NPL% (90+ days), by outstanding balance. */
    private function parAging(): array
    {
        $today = now()->startOfDay();
        $overdue = LoanSummary::overdueList($today)->keyBy('id');

        $buckets = [
            'current' => ['label' => 'Current (not overdue)', 'min' => null, 'max' => null, 'count' => 0, 'amount' => 0.0],
            'par1_29' => ['label' => '1-29 days', 'min' => 1, 'max' => 29, 'count' => 0, 'amount' => 0.0],
            'par30_59' => ['label' => '30-59 days', 'min' => 30, 'max' => 59, 'count' => 0, 'amount' => 0.0],
            'par60_89' => ['label' => '60-89 days', 'min' => 60, 'max' => 89, 'count' => 0, 'amount' => 0.0],
            'par90_plus' => ['label' => '90+ days (NPL)', 'min' => 90, 'max' => null, 'count' => 0, 'amount' => 0.0],
        ];

        $totalPortfolio = 0.0;
        Loan::where('status', 'Active')->select('id', 'outstanding_balance_cache')
            ->chunk(500, function ($loans) use (&$buckets, &$totalPortfolio, $overdue) {
                foreach ($loans as $loan) {
                    $balance = (float) $loan->outstanding_balance_cache;
                    $totalPortfolio += $balance;
                    $daysOverdue = (int) ($overdue->get($loan->id)['daysOverdue'] ?? 0);

                    $key = match (true) {
                        $daysOverdue <= 0 => 'current',
                        $daysOverdue < 30 => 'par1_29',
                        $daysOverdue < 60 => 'par30_59',
                        $daysOverdue < 90 => 'par60_89',
                        default => 'par90_plus',
                    };
                    $buckets[$key]['count']++;
                    $buckets[$key]['amount'] += $balance;
                }
            });

        foreach ($buckets as &$b) {
            $b['amount'] = round($b['amount'], 2);
            $b['pct'] = $totalPortfolio > 0 ? round(($b['amount'] / $totalPortfolio) * 100, 2) : 0.0;
        }
        unset($b);

        $atRisk30 = $buckets['par30_59']['amount'] + $buckets['par60_89']['amount'] + $buckets['par90_plus']['amount'];
        $atRisk60 = $buckets['par60_89']['amount'] + $buckets['par90_plus']['amount'];
        $nplAmount = $buckets['par90_plus']['amount'];

        return [
            'asOf' => $today->toDateString(),
            'totalPortfolio' => round($totalPortfolio, 2),
            'buckets' => array_values($buckets),
            'par30Pct' => $totalPortfolio > 0 ? round(($atRisk30 / $totalPortfolio) * 100, 2) : 0.0,
            'par60Pct' => $totalPortfolio > 0 ? round(($atRisk60 / $totalPortfolio) * 100, 2) : 0.0,
            'nplPct' => $totalPortfolio > 0 ? round(($nplAmount / $totalPortfolio) * 100, 2) : 0.0,
            'nplAmount' => round($nplAmount, 2),
        ];
    }

    private function disbursement(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return Loan::with(self::LIST_RELATIONS)
            ->whereNotNull('disbursed_at')
            ->whereBetween('disbursed_at', [$from, $to])
            ->orderByDesc('disbursed_at')
            ->get();
    }

    private function closedLoans(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return Loan::with(self::LIST_RELATIONS)
            ->where('status', 'Closed')
            ->whereBetween('closed_at', [$from, $to])
            ->orderByDesc('closed_at')
            ->get();
    }
}
