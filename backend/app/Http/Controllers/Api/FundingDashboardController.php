<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FdDeposit;
use App\Models\FdInterestPayment;
use App\Models\FdRenewal;
use App\Models\FixedDeposit;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;

class FundingDashboardController extends Controller
{
    public function index()
    {
        $today = now();
        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth = $today->copy()->endOfMonth();

        $savingsToday = SavingsTransaction::where('status', 'Posted')->whereDate('txn_date', $today);

        return [
            'total_funding_portfolio' => (float) FixedDeposit::where('status', 'Active')->sum('principal_amount'),
            'active_fds' => FixedDeposit::where('status', 'Active')->count(),
            'monthly_interest_payable' => (float) FdInterestPayment::where('status', 'Pending')
                ->whereDate('due_date', '>=', $startOfMonth)->whereDate('due_date', '<=', $endOfMonth)->sum('amount'),
            'maturing_this_month' => FixedDeposit::where('status', 'Active')
                ->whereDate('maturity_date', '>=', $today)->whereDate('maturity_date', '<=', $endOfMonth)->count(),
            'new_deposits_today' => (float) FdDeposit::whereDate('deposit_date', $today)->sum('amount'),
            'renewals_this_month' => FdRenewal::whereDate('renewed_at', '>=', $startOfMonth)->whereDate('renewed_at', '<=', $endOfMonth)->count(),

            // --- Daily Savings ---
            'total_savings_portfolio' => (float) SavingsAccount::where('status', '!=', 'Closed')->sum('current_balance'),
            'active_savings_accounts' => SavingsAccount::whereIn('status', ['Active', 'Dormant'])->count(),
            'dormant_savings_accounts' => SavingsAccount::where('status', 'Dormant')->count(),
            'savings_deposits_today' => (float) (clone $savingsToday)->whereIn('type', ['Deposit'])->where('direction', 'In')->sum('amount'),
            'savings_withdrawals_today' => (float) (clone $savingsToday)->where('type', 'Withdrawal')->sum('amount'),
        ];
    }
}
