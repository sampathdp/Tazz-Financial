<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\FacilityPayment;
use App\Models\FixedDeposit;
use App\Models\Loan;
use App\Models\LoanType;
use App\Models\Pawning;
use App\Models\SpeedDraft;
use App\Support\SpeedDraftReports;
use App\Support\CurrentBranch;
use App\Support\LoanSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The landing "Dashboard" (route id 'dash') — top-level KPIs across
 * portfolio, collections, funding and the ledger, for whoever has the
 * Dashboard permission (every role by default). Read-only, nothing
 * persisted: every figure is a live aggregate computed the same way as the
 * dedicated report it summarizes, and limited to the same branch scope:
 *
 *  - outstanding balances  = Loan Detail report (LoanSummary::liveOutstanding, not the stale cache column)
 *  - arrears / aging       = Arrears report (LoanSummary::overdueList)
 *  - disbursed             = Disbursement report (reschedules excluded)
 *  - collected             = Collection reports (Approved, realized loan receipts)
 *  - collection efficiency = Due Schedule report (Active/Lapsed loans, paid capped at due)
 *  - income / expense      = P&L (cash basis) for the same branches
 *  - expenses by category  = Expense Summary report (refundable deposits count only what is forfeited)
 *
 * Raw DB::table() queries bypass the BelongsToBranch global scope, so each
 * one here goes through CurrentBranch::applyScope() explicitly.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $branchIds = CurrentBranch::reportBranchIds();

        return Cache::remember('dashboard:summary:v3:' . md5(json_encode($branchIds)) . ':' . now()->format('Y-m-d-H-i'), 60, function () use ($branchIds) {
            $today = now()->startOfDay();
            $monthStart = now()->startOfMonth();

            $open = LoanSummary::liveOutstanding($today);
            $totalLoanPortfolio = $open->sum('principal');
            $outstandingLoanBalance = $open->sum('outstanding');

            $overdue = LoanSummary::overdueList($today);
            $arrearsAmount = round((float) $overdue->sum('overdueAmount') + (float) $overdue->sum('penaltyDue'), 2);
            $defaultLoans = $overdue->filter(fn ($l) => $l['daysOverdue'] >= 90)->count();

            $todaysCollections = FacilityPayment::whereHas('facility')
                ->where('status', 'Active')->where('approval_status', 'Approved')
                ->where(fn ($q) => $q->whereNull('clearance_status')->orWhere('clearance_status', 'Realized'))
                ->whereDate('paid_at', $today)->sum('amount');

            // Same statement as Accounting > Balance Sheet (contra accounts netted, inter-branch
            // eliminated, overdrafts reclassed, current-year profit in equity), so the two always agree.
            $bs = app(AccountingReportController::class)->show(Request::create('/', 'GET', ['as_of' => now()->toDateString()]), 'balance-sheet');
            $totalAssets = $bs['total_assets'];
            $totalLiabilities = $bs['total_liabilities'];
            $totalEquity = $bs['total_equity'];

            // Same branch filter as the P&L report (cash basis) — without it a branch user saw company-wide profit.
            $periodBalances = ChartOfAccount::balancesAsOf(now()->toDateString(), $monthStart->toDateString(), $branchIds);
            $monthIncome = $this->sumByType($periodBalances, 'Income');
            $monthExpense = $this->sumByType($periodBalances, 'Expense');

            $officers = DB::table('loan_facility_details')
                ->join('credit_facilities', 'credit_facilities.id', '=', 'loan_facility_details.credit_facility_id')
                ->whereIn('credit_facilities.status', ['Active', 'Lapsed'])
                ->where('credit_facilities.facility_type', 'Loan')
                ->whereNull('credit_facilities.deleted_at')
                ->whereNotNull('loan_facility_details.loan_officer_id');
            $activeLoanOfficers = CurrentBranch::applyScope($officers, 'credit_facilities.branch_id')
                ->distinct()->count('loan_facility_details.loan_officer_id');

            return array_merge(['speedDraft' => $this->speedDraft($today, $monthStart)], $this->insights($today, $overdue, $open, $branchIds), [
                'total_assets' => round((float) $totalAssets, 2),
                'total_liabilities' => round((float) $totalLiabilities, 2),
                'total_equity' => round((float) $totalEquity, 2),
                'total_deposits' => round((float) FixedDeposit::where('status', 'Active')->sum('principal_amount'), 2),
                'total_loan_portfolio' => round((float) $totalLoanPortfolio, 2),
                'outstanding_loan_balance' => round((float) $outstandingLoanBalance, 2),
                'todays_collections' => round((float) $todaysCollections, 2),
                'month_income' => round((float) $monthIncome, 2),
                'month_expense' => round((float) $monthExpense, 2),
                'net_profit' => round((float) $monthIncome - (float) $monthExpense, 2),
                'arrears_amount' => $arrearsAmount,
                'default_loans' => $defaultLoans,
                'active_customers' => Customer::where('status', 'Active')->count(),
                'active_loan_officers' => $activeLoanOfficers,
                'bank_balances' => round((float) BankAccount::where('status', 'Active')->sum('current_balance'), 2),
                'cash_in_hand' => round((float) CashAccount::where('status', 'Active')->sum('current_balance'), 2),
            ]);
        });
    }

    /**
     * Chart series for the dashboard: 12-month trends, portfolio mix,
     * arrears aging, branch comparison and the worst delinquent loans.
     *
     * @param  Collection  $open  LoanSummary::liveOutstanding() rows
     * @param  int[]|null  $branchIds  CurrentBranch::reportBranchIds()
     */
    private function insights(\Carbon\Carbon $today, Collection $overdue, Collection $open, ?array $branchIds): array
    {
        $start = now()->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'disbursed' => 0.0, 'collected' => 0.0, 'income' => 0.0, 'expense' => 0.0, 'newLoans' => 0];
        }

        // Eloquent (branch + soft-delete scopes apply). A reschedule moves no new money,
        // so it is excluded exactly as in the Disbursement report.
        $disb = Loan::whereNotNull('disbursed_at')->whereNull('restructured_from_id')
            ->where('disbursed_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(disbursed_at, '%Y-%m') as ym, SUM(COALESCE(disbursed_amount, principal_amount)) as amt, COUNT(*) as cnt")
            ->groupBy('ym')->toBase()->get();
        foreach ($disb as $r) {
            if (isset($months[$r->ym])) {
                $months[$r->ym]['disbursed'] = round((float) $r->amt, 2);
                $months[$r->ym]['newLoans'] = (int) $r->cnt;
            }
        }

        $collQuery = DB::table('facility_payments')
            ->join('credit_facilities', 'credit_facilities.id', '=', 'facility_payments.credit_facility_id')
            ->where('credit_facilities.facility_type', 'Loan')
            ->where('facility_payments.status', 'Active')->where('facility_payments.approval_status', 'Approved')
            ->where(fn ($q) => $q->whereNull('facility_payments.clearance_status')->orWhere('facility_payments.clearance_status', 'Realized'))
            ->where('facility_payments.paid_at', '>=', $start);
        $coll = CurrentBranch::applyScope($collQuery, 'credit_facilities.branch_id')
            ->selectRaw("DATE_FORMAT(facility_payments.paid_at, '%Y-%m') as ym, SUM(facility_payments.amount) as amt")
            ->groupBy('ym')->get();
        foreach ($coll as $r) {
            if (isset($months[$r->ym])) {
                $months[$r->ym]['collected'] = round((float) $r->amt, 2);
            }
        }

        $pl = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('chart_of_accounts.account_type', ['Income', 'Expense'])
            ->where('journal_entries.entry_date', '>=', $start->toDateString())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('journal_entries.branch_id', $branchIds))
            ->selectRaw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m') as ym, chart_of_accounts.account_type as t, SUM(journal_lines.credit) - SUM(journal_lines.debit) as net")
            ->groupBy('ym', 't')->get();
        foreach ($pl as $r) {
            if (!isset($months[$r->ym])) {
                continue;
            }
            if ($r->t === 'Income') {
                $months[$r->ym]['income'] = round((float) $r->net, 2);
            } else {
                $months[$r->ym]['expense'] = round(-(float) $r->net, 2);
            }
        }

        // Portfolio mix from the same live per-loan balances as the headline KPI,
        // so the donut/bars always add up to "Outstanding Loan Balance".
        $typeNames = LoanType::whereIn('id', $open->pluck('loan_type_id')->filter()->unique())->pluck('name', 'id');
        $byType = $open->groupBy(fn ($r) => $typeNames[$r['loan_type_id']] ?? 'Unspecified')
            ->map(fn ($g, $name) => ['name' => $name, 'count' => $g->count(), 'outstanding' => round((float) $g->sum('outstanding'), 2)])
            ->sortByDesc('outstanding')->values();

        $branchNames = Branch::whereIn('id', $open->pluck('branch_id')->filter()->unique())->pluck('name', 'id');
        $byBranch = $open->groupBy(fn ($r) => $branchNames[$r['branch_id']] ?? 'Unassigned')
            ->map(fn ($g, $name) => ['name' => $name, 'count' => $g->count(), 'outstanding' => round((float) $g->sum('outstanding'), 2)])
            ->sortByDesc('outstanding')->take(8)->values();

        $statusCounts = Loan::selectRaw('status, COUNT(*) as cnt')->groupBy('status')->toBase()->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->cnt])->values();

        $buckets = [['1-30', 1, 30], ['31-60', 31, 60], ['61-90', 61, 90], ['90+', 91, PHP_INT_MAX]];
        $agingOut = array_map(function ($b) use ($overdue) {
            $rows = $overdue->filter(fn ($l) => $l['daysOverdue'] >= $b[1] && $l['daysOverdue'] <= $b[2]);
            return ['bucket' => $b[0], 'count' => $rows->count(), 'amount' => round((float) $rows->sum('overdueAmount') + (float) $rows->sum('penaltyDue'), 2)];
        }, $buckets);

        $topOverdue = $overdue->take(5)->map(fn ($l) => [
            'code' => $l['code'] ?? null,
            'customer' => $l['customer']['full_name'] ?? '—',
            'branch' => $l['branch']['name'] ?? null,
            'daysOverdue' => $l['daysOverdue'],
            'amount' => round((float) $l['overdueAmount'] + (float) $l['penaltyDue'], 2),
        ])->values();

        // Same rule as the Expense Summary report: a refundable deposit is only a cost to the extent it is forfeited.
        $expenseByCategory = Expense::with('category:id,name')
            ->whereIn('status', ['Approved', 'Paid'])
            ->whereBetween('expense_date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->get()
            ->groupBy(fn ($e) => $e->category?->name ?? 'Other')
            ->map(fn ($g, $name) => ['name' => $name, 'amount' => round((float) $g->sum(fn ($e) => $e->is_refundable ? (float) $e->forfeited_amount : (float) $e->amount), 2)])
            ->filter(fn ($r) => $r['amount'] > 0)
            ->sortByDesc('amount')->take(6)->values();

        $pawn = fn () => Pawning::where('status', 'Active');
        $pending = [
            'loanApplications' => Loan::where('status', 'Pending')->count(),
            'pawningActive' => $pawn()->count(),
            'pawningValue' => round((float) $pawn()->sum('principal_amount'), 2),
            'fdMaturing30' => FixedDeposit::where('status', 'Active')->whereBetween('maturity_date', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])->count(),
        ];

        // Same basis as Collection Reports > Due Schedule: Active/Lapsed loans, paid capped at
        // what was due on each row (an overpaid installment must not inflate efficiency).
        $dueQuery = DB::table('facility_schedules as s')
            ->join('credit_facilities as cf', 'cf.id', '=', 's.credit_facility_id')
            ->where('cf.facility_type', 'Loan')->whereIn('cf.status', ['Active', 'Lapsed'])->whereNull('cf.deleted_at')
            ->whereBetween('s.due_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        $dueTotals = CurrentBranch::applyScope($dueQuery, 'cf.branch_id')
            ->selectRaw('COALESCE(SUM(s.total_due), 0) as due, COALESCE(SUM(LEAST(s.paid_amount, s.total_due)), 0) as paid')
            ->first();
        $dueThisMonth = (float) $dueTotals->due;
        $paidThisMonth = (float) $dueTotals->paid;

        return [
            'trend' => array_values($months),
            'portfolioByType' => $byType,
            'portfolioByBranch' => $byBranch,
            'loanStatusCounts' => $statusCounts,
            'arrearsAging' => $agingOut,
            'topOverdue' => $topOverdue,
            'expenseByCategory' => $expenseByCategory,
            'overdueLoanCount' => $overdue->count(),
            'collectionEfficiency' => $dueThisMonth > 0 ? round(min(100, $paidThisMonth / $dueThisMonth * 100), 1) : null,
            'dueThisMonth' => round($dueThisMonth, 2),
            'pending' => $pending,
        ];
    }

    /**
     * Speed Draft block: live capital/interest/arrears from SpeedDraftReports (same source as the
     * Speed Draft reports), plus disbursed/collected for the month and a 12-month trend.
     */
    private function speedDraft(\Carbon\Carbon $today, \Carbon\Carbon $monthStart): array
    {
        $active = SpeedDraftReports::activeRows();
        $overdue = $active->where('daysOverdue', '>', 0);
        $capital = (float) $active->sum('outstandingCapital');

        $buckets = [['1-30', 1, 30], ['31-60', 31, 60], ['61-90', 61, 90], ['90+', 91, PHP_INT_MAX]];
        $aging = array_map(function ($b) use ($overdue) {
            $rows = $overdue->filter(fn ($r) => $r['daysOverdue'] >= $b[1] && $r['daysOverdue'] <= $b[2]);

            return ['bucket' => $b[0], 'count' => $rows->count(), 'amount' => round((float) $rows->sum('overdueInterest') + (float) $rows->sum('penaltyDue'), 2)];
        }, $buckets);

        $start = now()->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'disbursed' => 0.0, 'collected' => 0.0];
        }
        $disb = SpeedDraft::whereNotNull('disbursed_at')->where('disbursed_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(disbursed_at, '%Y-%m') as ym, SUM(COALESCE(disbursed_amount, principal_amount)) as amt")
            ->groupBy('ym')->toBase()->get();
        foreach ($disb as $r) {
            if (isset($months[$r->ym])) {
                $months[$r->ym]['disbursed'] = round((float) $r->amt, 2);
            }
        }
        $collQuery = DB::table('facility_payments')
            ->join('credit_facilities', 'credit_facilities.id', '=', 'facility_payments.credit_facility_id')
            ->where('credit_facilities.facility_type', 'SpeedDraft')->whereNull('credit_facilities.deleted_at')
            ->where('facility_payments.status', 'Active')->where('facility_payments.approval_status', 'Approved')
            ->where(fn ($q) => $q->whereNull('facility_payments.clearance_status')->orWhere('facility_payments.clearance_status', 'Realized'))
            ->where('facility_payments.paid_at', '>=', $start);
        $coll = CurrentBranch::applyScope($collQuery, 'credit_facilities.branch_id')
            ->selectRaw("DATE_FORMAT(facility_payments.paid_at, '%Y-%m') as ym, SUM(facility_payments.amount) as amt, SUM(CASE WHEN DATE(facility_payments.paid_at) = ? THEN facility_payments.amount ELSE 0 END) as today_amt", [$today->toDateString()])
            ->groupBy('ym')->get();
        $collectedToday = 0.0;
        foreach ($coll as $r) {
            $collectedToday += (float) $r->today_amt;
            if (isset($months[$r->ym])) {
                $months[$r->ym]['collected'] = round((float) $r->amt, 2);
            }
        }

        $byType = $active->groupBy('type')->map(fn ($g, $n) => ['name' => $n, 'count' => $g->count(), 'outstanding' => round((float) $g->sum('outstandingCapital'), 2)])->sortByDesc('outstanding')->values();
        $maturing = $active->filter(fn ($r) => $r['maturityDate'] && $r['maturityDate'] >= $today->toDateString() && $r['maturityDate'] <= $today->copy()->addDays(30)->toDateString());

        return [
            'activeCount' => $active->count(),
            'pendingCount' => SpeedDraft::where('status', 'Pending')->count(),
            'outstandingCapital' => round($capital, 2),
            'outstandingInterest' => round((float) $active->sum('outstandingInterest'), 2),
            'penaltyDue' => round((float) $active->sum('penaltyDue'), 2),
            'receivable' => round((float) $active->sum('receivable'), 2),
            'overdueCount' => $overdue->count(),
            'arrearsAmount' => round((float) $overdue->sum('overdueInterest') + (float) $overdue->sum('penaltyDue'), 2),
            'nplPct' => $capital > 0 ? round((float) $overdue->where('daysOverdue', '>=', 90)->sum('outstandingCapital') / $capital * 100, 2) : 0.0,
            'disbursedThisMonth' => round((float) SpeedDraft::whereNotNull('disbursed_at')->where('disbursed_at', '>=', $monthStart)->sum(DB::raw('COALESCE(disbursed_amount, principal_amount)')), 2),
            'collectedToday' => round($collectedToday, 2),
            'collectedThisMonth' => round((float) ($months[now()->format('Y-m')]['collected'] ?? 0), 2),
            'maturing30Count' => $maturing->count(),
            'maturing30Capital' => round((float) $maturing->sum('outstandingCapital'), 2),
            'trend' => array_values($months),
            'byType' => $byType,
            'arrearsAging' => $aging,
            'topOverdue' => $overdue->sortByDesc('daysOverdue')->take(5)->map(fn ($r) => [
                'code' => $r['code'], 'customer' => $r['customer'], 'branch' => $r['branch'], 'daysOverdue' => $r['daysOverdue'],
                'amount' => round($r['overdueInterest'] + $r['penaltyDue'], 2),
            ])->values(),
        ];
    }

    private function sumByType(Collection $balances, string $accountType): string
    {
        return ChartOfAccount::where('account_type', $accountType)->get(['id'])
            ->reduce(fn ($sum, $account) => bcadd($sum, $balances->get($account->id)->balance ?? '0.00', 2), '0.00');
    }
}
