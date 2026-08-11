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
        return Cache::remember('dashboard:summary:' . now()->format('Y-m-d-H-i'), 60, function () {
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
                ->whereDate('paid_at', $today)->sum('amount');

            $glBalances = ChartOfAccount::balancesAsOf(now()->toDateString());
            $totalAssets = $this->sumByType($glBalances, 'Asset');
            $totalLiabilities = $this->sumByType($glBalances, 'Liability');

            $periodBalances = ChartOfAccount::balancesAsOf(now()->toDateString(), $monthStart->toDateString());
            $monthIncome = $this->sumByType($periodBalances, 'Income');
            $monthExpense = $this->sumByType($periodBalances, 'Expense');

            $activeLoanOfficers = DB::table('loan_facility_details')
                ->join('credit_facilities', 'credit_facilities.id', '=', 'loan_facility_details.credit_facility_id')
                ->where('credit_facilities.status', 'Active')
                ->where('credit_facilities.facility_type', 'Loan')
                ->whereNotNull('loan_facility_details.loan_officer_id')
                ->distinct()->count('loan_facility_details.loan_officer_id');

            return [
                'total_assets' => round((float) $totalAssets, 2),
                'total_liabilities' => round((float) $totalLiabilities, 2),
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
            ];
        });
    }

    private function sumByType(\Illuminate\Support\Collection $balances, string $accountType): string
    {
        return ChartOfAccount::where('account_type', $accountType)->get(['id'])
            ->reduce(fn ($sum, $account) => bcadd($sum, $balances->get($account->id)->balance ?? '0.00', 2), '0.00');
    }
}
