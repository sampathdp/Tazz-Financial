<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Support\LoanSummary;
use App\Support\ReportExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Loan portfolio reports (Reports > Loan Reports). Same type-dispatch shape
 * as FundingReportController/AccountingReportController: one route, an
 * allow-listed TYPES const, one private method per type, everything computed
 * live off credit_facilities/facility_schedules — nothing persisted here.
 */
class LoanReportController extends Controller
{
    private const TYPES = ['portfolio-summary', 'arrears', 'par-aging', 'disbursement', 'closed-loans', 'loan-detail'];

    private const LIST_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone',
        'branch:id,name,code',
        'loanDetail.loanType:id,name,code',
        'loanDetail.loanOfficer:id,name',
    ];

    /** Every relation needed to flatten a loan to one wide row for the
     *  'loan-detail' export — the only report type here that isn't a fixed
     *  metric/period roll-up, so it pulls in far more than LIST_RELATIONS. */
    private const DETAIL_RELATIONS = [
        'customer:id,full_name,code,nic_no,passport_no,phone,email,address,occupation_type,monthly_income',
        'branch:id,name,code',
        'loanDetail.loanType:id,name,code,category,default_charge_rate_percent,grace_period_days',
        'loanDetail.loanOfficer:id,name',
        'loanDetail.recoveryOfficer:id,name',
        'approver:id,name',
        'guarantors.guarantor:id,full_name',
        'coApplicants.coApplicant:id,full_name',
        'collateral:id,credit_facility_id,collateral_type,description,estimated_value',
        'insurance',
        'charges:id,credit_facility_id,due_amount,paid_amount',
        'schedules:id,credit_facility_id,due_date,status,principal_due,interest_due,total_due,paid_amount',
    ];

    /** Which loan date the from/to range filters against — 'applicationDate'
     *  lives on loanDetail, the rest are columns on credit_facilities itself. */
    private const DATE_FIELDS = ['applicationDate', 'disbursedAt', 'closedAt', 'startDate', 'createdAt'];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'portfolio-summary' => $this->portfolioSummary($request),
            'arrears' => $this->arrears($request),
            'par-aging' => $this->parAging($request),
            'disbursement' => $this->disbursement($request),
            'closed-loans' => $this->closedLoans($request),
            'loan-detail' => $this->loanDetail($request),
        };
    }

    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }
        // Loan Detail is a wide (40+ column) per-loan export — unlike every other
        // report here it doesn't fit a printable PDF table, so it's Excel-only
        // (see LoanReports.tsx, which never passes onPdf for this screen).
        if ($type === 'loan-detail') {
            return response()->json(['message' => 'The Loan Detail report is available as Excel export only.'], 422);
        }

        [$title, $sections, $meta] = match ($type) {
            'portfolio-summary' => $this->portfolioSummaryPdf($request),
            'arrears' => $this->arrearsPdf($request),
            'par-aging' => $this->parAgingPdf($request),
            'disbursement' => $this->disbursementPdf($request),
            'closed-loans' => $this->closedLoansPdf($request),
        };

        return ReportExport::pdf($title, $sections, null, $meta)->download(ReportExport::filename($type));
    }

    private function portfolioSummaryPdf(Request $request): array
    {
        $d = $this->portfolioSummary($request);
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

    private function parAgingPdf(Request $request): array
    {
        $d = $this->parAging($request);
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
    private function portfolioSummary(Request $request): array
    {
        $loanTypeId = $request->filled('loanTypeId') ? (int) $request->loanTypeId : null;
        $byLoanType = fn ($q) => $q->when($loanTypeId, fn ($q2) => $q2->whereHas('loanDetail', fn ($q3) => $q3->where('loan_type_id', $loanTypeId)));

        $counts = $byLoanType(Loan::query())->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $active = $byLoanType(Loan::where('status', 'Active'));
        $totalOutstanding = (clone $active)->sum('outstanding_balance_cache');
        $activeCount = (clone $active)->count();

        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = now()->startOfMonth();

        $thisMonth = $byLoanType(Loan::whereNotNull('disbursed_at')->where('disbursed_at', '>=', $monthStart));
        $lastMonth = $byLoanType(Loan::whereNotNull('disbursed_at')->whereBetween('disbursed_at', [$lastMonthStart, $lastMonthEnd]));
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
        $loanTypeId = $request->filled('loanTypeId') ? (int) $request->loanTypeId : null;
        $loanOfficerId = $request->filled('loanOfficerId') ? (int) $request->loanOfficerId : null;

        return LoanSummary::overdueList($today, $from, $loanTypeId, $loanOfficerId);
    }

    /** PAR 30/60/90 aging buckets + NPL% (90+ days), by outstanding balance. */
    private function parAging(Request $request): array
    {
        $today = now()->startOfDay();
        $loanTypeId = $request->filled('loanTypeId') ? (int) $request->loanTypeId : null;
        // Only daysOverdue (from the oldest unpaid schedule) is needed here —
        // not the money figures LoanSummary::overdueList() also computes
        // (including the per-row penalty calc), so this queries just the
        // oldest overdue due_date per loan directly instead of loading full
        // Loan models with their relations.
        $oldestDueDate = DB::table('facility_schedules')
            ->join('credit_facilities', 'credit_facilities.id', '=', 'facility_schedules.credit_facility_id')
            ->where('credit_facilities.status', 'Active')
            ->where('facility_schedules.due_date', '<', $today)
            ->where('facility_schedules.status', '!=', 'Paid')
            ->when($loanTypeId, function ($q) use ($loanTypeId) {
                $q->join('loan_details', 'loan_details.credit_facility_id', '=', 'credit_facilities.id')
                    ->where('loan_details.loan_type_id', $loanTypeId);
            })
            ->groupBy('facility_schedules.credit_facility_id')
            ->selectRaw('facility_schedules.credit_facility_id as loan_id, MIN(facility_schedules.due_date) as oldest_due_date')
            ->pluck('oldest_due_date', 'loan_id');

        $buckets = [
            'current' => ['label' => 'Current (not overdue)', 'min' => null, 'max' => null, 'count' => 0, 'amount' => 0.0],
            'par1_29' => ['label' => '1-29 days', 'min' => 1, 'max' => 29, 'count' => 0, 'amount' => 0.0],
            'par30_59' => ['label' => '30-59 days', 'min' => 30, 'max' => 59, 'count' => 0, 'amount' => 0.0],
            'par60_89' => ['label' => '60-89 days', 'min' => 60, 'max' => 89, 'count' => 0, 'amount' => 0.0],
            'par90_plus' => ['label' => '90+ days (NPL)', 'min' => 90, 'max' => null, 'count' => 0, 'amount' => 0.0],
        ];

        $totalPortfolio = 0.0;
        Loan::where('status', 'Active')->select('id', 'outstanding_balance_cache')
            ->chunk(500, function ($loans) use (&$buckets, &$totalPortfolio, $oldestDueDate, $today) {
                foreach ($loans as $loan) {
                    $balance = (float) $loan->outstanding_balance_cache;
                    $totalPortfolio += $balance;
                    $oldest = $oldestDueDate->get($loan->id);
                    $daysOverdue = $oldest ? (int) $today->diffInDays(Carbon::parse($oldest)) : 0;

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
            ->when($request->filled('loanTypeId'), fn ($q) => $q->whereHas('loanDetail', fn ($q2) => $q2->where('loan_type_id', $request->loanTypeId)))
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
            ->when($request->filled('loanTypeId'), fn ($q) => $q->whereHas('loanDetail', fn ($q2) => $q2->where('loan_type_id', $request->loanTypeId)))
            ->orderByDesc('closed_at')
            ->get();
    }

    /**
     * Loan Detail Report: every loan flattened to one row, covering the
     * customer, product, financial, officer, and recovery-status fields
     * needed for a full-portfolio export — no fixed period, filtered
     * entirely by the params below so it doubles as the "customized" report.
     * daysOverdue/overdueAmount/penaltyDue are computed the same way
     * LoanSummary::overdueList() does (so this can never disagree with the
     * Arrears report or Loan Detail screen) but inline here since every loan
     * needs the figure, not just already-overdue ones.
     */
    private function loanDetail(Request $request)
    {
        $query = Loan::with(self::DETAIL_RELATIONS)
            ->withSum('schedules as total_due_sum', 'total_due')
            ->withSum('schedules as paid_sum', 'paid_amount');

        if ($request->filled('branchId')) {
            $query->where('branch_id', $request->branchId);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('minPrincipal')) {
            $query->where('principal_amount', '>=', $request->minPrincipal);
        }
        if ($request->filled('maxPrincipal')) {
            $query->where('principal_amount', '<=', $request->maxPrincipal);
        }
        if ($request->filled('loanTypeId') || $request->filled('loanOfficerId') || $request->filled('recoveryOfficerId')) {
            $query->whereHas('loanDetail', function ($q) use ($request) {
                if ($request->filled('loanTypeId')) {
                    $q->where('loan_type_id', $request->loanTypeId);
                }
                if ($request->filled('loanOfficerId')) {
                    $q->where('loan_officer_id', $request->loanOfficerId);
                }
                if ($request->filled('recoveryOfficerId')) {
                    $q->where('recovery_officer_id', $request->recoveryOfficerId);
                }
            });
        }
        if ($request->filled('customerSearch')) {
            $term = $request->customerSearch;
            $query->whereHas('customer', fn ($q) => $q->where('full_name', 'like', "%{$term}%")
                ->orWhere('nic_no', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%"));
        }

        $dateField = in_array($request->dateField, self::DATE_FIELDS, true) ? $request->dateField : 'createdAt';
        if ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;
            $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : null;
            if ($dateField === 'applicationDate') {
                $query->whereHas('loanDetail', function ($q) use ($from, $to) {
                    if ($from) {
                        $q->where('application_date', '>=', $from->toDateString());
                    }
                    if ($to) {
                        $q->where('application_date', '<=', $to->toDateString());
                    }
                });
            } else {
                $column = ['disbursedAt' => 'disbursed_at', 'closedAt' => 'closed_at', 'startDate' => 'start_date', 'createdAt' => 'created_at'][$dateField];
                if ($from) {
                    $query->where($column, '>=', $from);
                }
                if ($to) {
                    $query->where($column, '<=', $to);
                }
            }
        }

        $today = Carbon::today();
        $rows = $query->orderByDesc('id')->get()->map(function (Loan $loan) use ($today) {
            $loanType = $loan->loanDetail?->loanType;
            $graceDays = (int) ($loanType?->grace_period_days ?? 5);
            $chargeRate = (float) ($loanType?->default_charge_rate_percent ?? 0);
            $overdueSchedules = $loan->schedules->filter(fn ($s) => $s->due_date->lessThan($today) && $s->status !== 'Paid');
            $oldest = $overdueSchedules->min('due_date');
            $daysOverdue = $oldest ? (int) $today->diffInDays($oldest) : 0;
            $overdueAmount = round((float) $overdueSchedules->sum(
                fn ($s) => max(0, (float) $s->principal_due + (float) $s->interest_due - (float) $s->paid_amount)
            ), 2);
            $penaltyDue = round((float) $overdueSchedules->sum(
                fn ($s) => LoanSummary::penaltyDueForRow($s, $graceDays, $chargeRate, $today)
            ), 2);

            return [
                'code' => $loan->code,
                'status' => $loan->status,
                'branch' => $loan->branch?->name,
                'branchCode' => $loan->branch?->code,
                'customerCode' => $loan->customer?->code,
                'customerName' => $loan->customer?->full_name,
                'nicNo' => $loan->customer?->nic_no,
                'passportNo' => $loan->customer?->passport_no,
                'phone' => $loan->customer?->phone,
                'email' => $loan->customer?->email,
                'address' => $loan->customer?->address,
                'occupation' => $loan->customer?->occupation_type,
                'monthlyIncome' => (float) ($loan->customer?->monthly_income ?? 0),
                'loanType' => $loanType?->name,
                'loanCategory' => $loanType?->category,
                'principal' => (float) $loan->principal_amount,
                'interestRate' => (float) ($loan->interest_rate_percent ?? 0),
                'ratePeriod' => $loan->rate_period,
                'termMonths' => $loan->term_months,
                'frequency' => $loan->loanDetail?->installment_frequency,
                'interestMethod' => $loan->loanDetail?->interest_method,
                'downPayment' => (float) ($loan->loanDetail?->down_payment ?? 0),
                'purpose' => $loan->loanDetail?->purpose,
                'applicationDate' => optional($loan->loanDetail?->application_date)->toDateString(),
                'approvedAt' => optional($loan->approved_at)->toDateString(),
                'approvedBy' => $loan->approver?->name,
                'disbursedAt' => optional($loan->disbursed_at)->toDateString(),
                'disbursedAmount' => (float) ($loan->disbursed_amount ?? 0),
                'firstInstallmentDate' => optional($loan->loanDetail?->first_installment_date)->toDateString(),
                'startDate' => optional($loan->start_date)->toDateString(),
                'maturityDate' => optional($loan->maturity_date)->toDateString(),
                'closedAt' => optional($loan->closed_at)->toDateString(),
                'loanOfficer' => $loan->loanDetail?->loanOfficer?->name,
                'recoveryOfficer' => $loan->loanDetail?->recoveryOfficer?->name,
                'referenceName' => $loan->loanDetail?->reference_name,
                'referenceIdNo' => $loan->loanDetail?->reference_id_no,
                'referenceAddress' => $loan->loanDetail?->reference_address,
                'vehicleNo' => $loan->loanDetail?->vehicle_no,
                'guarantors' => $loan->guarantors->map(fn ($g) => $g->guarantor?->full_name)->filter()->implode(', '),
                'coApplicants' => $loan->coApplicants->map(fn ($c) => $c->coApplicant?->full_name)->filter()->implode(', '),
                'collateral' => $loan->collateral->map(fn ($c) => $c->description)->filter()->implode(', '),
                'collateralValue' => round((float) $loan->collateral->sum('estimated_value'), 2),
                'insuranceCompany' => $loan->insurance?->insurance_company,
                'insurancePolicyNo' => $loan->insurance?->policy_no,
                'insuranceAmount' => (float) ($loan->insurance?->amount ?? 0),
                'chargesDue' => round((float) $loan->charges->sum('due_amount'), 2),
                'chargesPaid' => round((float) $loan->charges->sum('paid_amount'), 2),
                'totalScheduled' => round((float) ($loan->total_due_sum ?? 0), 2),
                'totalPaid' => round((float) ($loan->paid_sum ?? 0), 2),
                'outstandingBalance' => round((float) ($loan->outstanding_balance_cache ?? 0), 2),
                'daysOverdue' => $daysOverdue,
                'overdueAmount' => $overdueAmount,
                'penaltyDue' => $penaltyDue,
                'rejectedReason' => $loan->rejected_reason,
                'cancelledReason' => $loan->cancelled_reason,
                'cancelledAt' => optional($loan->cancelled_at)->toDateString(),
                'createdAt' => optional($loan->created_at)->toDateString(),
            ];
        });

        if ($request->boolean('overdueOnly')) {
            $rows = $rows->filter(fn ($r) => $r['daysOverdue'] > 0)->values();
        }

        return $rows;
    }
}
