<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\FacilityPayment;
use App\Models\FixedDeposit;
use App\Models\Loan;
use App\Support\LoanSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The landing "Dashboard" (route id 'dash') — top-level KPIs across
 * portfolio, collections, funding and the ledger, for whoever has the
 * Dashboard permission (every role by default). Read-only, nothing
 * persisted: every figure is a live aggregate over the same tables the
 * dedicated Loan/Collection/Accounting/Funding reports use, so this can
 * never disagree with them.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return Cache::remember('dashboard:summary:' . md5(json_encode(\App\Support\CurrentBranch::reportBranchIds())) . ':' . now()->format('Y-m-d-H-i'), 60, function () {
            $today = now()->startOfDay();
            $monthStart = now()->startOfMonth();

            $activeLoans = Loan::where('status', 'Active');
            $totalLoanPortfolio = (clone $activeLoans)->sum('principal_amount');
            $outstandingLoanBalance = (clone $activeLoans)->sum('outstanding_balance_cache');

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

            $periodBalances = ChartOfAccount::balancesAsOf(now()->toDateString(), $monthStart->toDateString());
            $monthIncome = $this->sumByType($periodBalances, 'Income');
            $monthExpense = $this->sumByType($periodBalances, 'Expense');

            $activeLoanOfficers = DB::table('loan_facility_details')
                ->join('credit_facilities', 'credit_facilities.id', '=', 'loan_facility_details.credit_facility_id')
                ->where('credit_facilities.status', 'Active')
                ->where('credit_facilities.facility_type', 'Loan')
                ->whereNotNull('loan_facility_details.loan_officer_id')
                ->distinct()->count('loan_facility_details.loan_officer_id');

            return array_merge($this->insights($today, $overdue), [
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
     */
    private function insights(\Carbon\Carbon $today, \Illuminate\Support\Collection $overdue): array
    {
        $start = now()->startOfMonth()->subMonths(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'disbursed' => 0.0, 'collected' => 0.0, 'income' => 0.0, 'expense' => 0.0, 'newLoans' => 0];
        }

        $disb = DB::table('credit_facilities')
            ->where('facility_type', 'Loan')->whereNull('deleted_at')->whereNotNull('disbursed_at')
            ->where('disbursed_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(disbursed_at, '%Y-%m') as ym, SUM(COALESCE(disbursed_amount, principal_amount)) as amt, COUNT(*) as cnt")
            ->groupBy('ym')->get();
        foreach ($disb as $r) {
            if (isset($months[$r->ym])) {
                $months[$r->ym]['disbursed'] = round((float) $r->amt, 2);
                $months[$r->ym]['newLoans'] = (int) $r->cnt;
            }
        }

        $coll = DB::table('facility_payments')
            ->join('credit_facilities', 'credit_facilities.id', '=', 'facility_payments.credit_facility_id')
            ->where('credit_facilities.facility_type', 'Loan')
            ->where('facility_payments.status', 'Active')->where('facility_payments.approval_status', 'Approved')
            ->where(fn ($q) => $q->whereNull('facility_payments.clearance_status')->orWhere('facility_payments.clearance_status', 'Realized'))
            ->where('facility_payments.paid_at', '>=', $start)
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

        $byType = DB::table('credit_facilities as cf')
            ->join('loan_facility_details as d', 'd.credit_facility_id', '=', 'cf.id')
            ->leftJoin('loan_types as lt', 'lt.id', '=', 'd.loan_type_id')
            ->where('cf.facility_type', 'Loan')->where('cf.status', 'Active')->whereNull('cf.deleted_at')
            ->selectRaw("COALESCE(lt.name, 'Unspecified') as name, COUNT(*) as cnt, SUM(cf.outstanding_balance_cache) as outstanding")
            ->groupBy('name')->orderByDesc('outstanding')->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->cnt, 'outstanding' => round((float) $r->outstanding, 2)])->values();

        $byBranch = DB::table('credit_facilities as cf')
            ->leftJoin('branches as b', 'b.id', '=', 'cf.branch_id')
            ->where('cf.facility_type', 'Loan')->where('cf.status', 'Active')->whereNull('cf.deleted_at')
            ->selectRaw("COALESCE(b.name, 'Unassigned') as name, COUNT(*) as cnt, SUM(cf.outstanding_balance_cache) as outstanding")
            ->groupBy('name')->orderByDesc('outstanding')->limit(8)->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->cnt, 'outstanding' => round((float) $r->outstanding, 2)])->values();

        $statusCounts = DB::table('credit_facilities')
            ->where('facility_type', 'Loan')->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->get()
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

        $expenseByCategory = DB::table('expenses as e')
            ->leftJoin('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->whereIn('e.status', ['Approved', 'Paid'])
            ->where('e.expense_date', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw("COALESCE(c.name, 'Other') as name, SUM(e.amount) as amt")
            ->groupBy('name')->orderByDesc('amt')->limit(6)->get()
            ->map(fn ($r) => ['name' => $r->name, 'amount' => round((float) $r->amt, 2)])->values();

        $pawn = fn () => DB::table('credit_facilities')->where('facility_type', 'Pawning')->where('status', 'Active')->whereNull('deleted_at');
        $pending = [
            'loanApplications' => DB::table('credit_facilities')->where('facility_type', 'Loan')->where('status', 'Pending')->whereNull('deleted_at')->count(),
            'pawningActive' => $pawn()->count(),
            'pawningValue' => round((float) $pawn()->sum('principal_amount'), 2),
            'fdMaturing30' => FixedDeposit::where('status', 'Active')->whereBetween('maturity_date', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])->count(),
        ];

        $monthSchedules = fn () => DB::table('facility_schedules as s')
            ->join('credit_facilities as cf', 'cf.id', '=', 's.credit_facility_id')
            ->where('cf.facility_type', 'Loan')->where('cf.status', 'Active')
            ->whereBetween('s.due_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        $dueThisMonth = (float) $monthSchedules()->sum('s.total_due');
        $paidThisMonth = (float) $monthSchedules()->sum('s.paid_amount');

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

    private function sumByType(\Illuminate\Support\Collection $balances, string $accountType): string
    {
        return ChartOfAccount::where('account_type', $accountType)->get(['id'])
            ->reduce(fn ($sum, $account) => bcadd($sum, $balances->get($account->id)->balance ?? '0.00', 2), '0.00');
    }
}
