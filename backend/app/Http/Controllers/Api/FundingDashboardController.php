<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FdDeposit;
use App\Models\FdInterestPayment;
use App\Models\FdRenewal;
use App\Models\FixedDeposit;

class FundingDashboardController extends Controller
{
    public function index()
    {
        $today = now();
        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth = $today->copy()->endOfMonth();

        return [
            'total_funding_portfolio' => (float) FixedDeposit::where('status', 'Active')->sum('principal_amount'),
            'active_fds' => FixedDeposit::where('status', 'Active')->count(),
            'monthly_interest_payable' => (float) FdInterestPayment::where('status', 'Pending')
                ->whereDate('due_date', '>=', $startOfMonth)->whereDate('due_date', '<=', $endOfMonth)->sum('amount'),
            'maturing_this_month' => FixedDeposit::where('status', 'Active')
                ->whereDate('maturity_date', '>=', $today)->whereDate('maturity_date', '<=', $endOfMonth)->count(),
            'new_deposits_today' => (float) FdDeposit::whereDate('deposit_date', $today)->sum('amount'),
            'renewals_this_month' => FdRenewal::whereDate('renewed_at', '>=', $startOfMonth)->whereDate('renewed_at', '<=', $endOfMonth)->count(),
        ];
    }
}
