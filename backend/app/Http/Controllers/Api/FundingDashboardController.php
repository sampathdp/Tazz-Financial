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

        // Pending interest on Active FDs due on/before today (overdue + today), oldest first.
        $dueRows = FdInterestPayment::query()
            ->where('status', 'Pending')
            ->whereDate('due_date', '<=', $today)
            ->whereHas('fixedDeposit', fn ($q) => $q->where('status', 'Active'))
            ->with(['fixedDeposit:id,fd_number,customer_id', 'fixedDeposit.customer:id,full_name'])
            ->orderBy('due_date')->get();
        $todayStr = $today->format('Y-m-d');
        $dueItems = $dueRows->map(fn ($r) => [
            'id' => $r->id,
            'fd_number' => $r->fixedDeposit->fd_number,
            'customer_name' => $r->fixedDeposit->customer?->full_name,
            'installment_no' => $r->installment_no,
            'due_date' => \Carbon\Carbon::parse($r->due_date)->format('Y-m-d'),
            'amount' => (float) $r->amount,
        ])->values();

        // Interest payable per month, current month + next 5 (Pending only).
        $months = collect(range(0, 5))->map(fn ($i) => $startOfMonth->copy()->addMonths($i));
        $interestByMonth = $months->map(function ($m) {
            return [
                'label' => $m->format('M y'),
                'payable' => (float) FdInterestPayment::where('status', 'Pending')
                    ->whereDate('due_date', '>=', $m->copy()->startOfMonth())->whereDate('due_date', '<=', $m->copy()->endOfMonth())
                    ->whereHas('fixedDeposit', fn ($q) => $q->where('status', 'Active'))->sum('amount'),
                'paid' => (float) FdInterestPayment::where('status', 'Paid')
                    ->whereDate('paid_at', '>=', $m->copy()->startOfMonth())->whereDate('paid_at', '<=', $m->copy()->endOfMonth())->sum('paid_amount'),
            ];
        })->values();

        // Principal maturing per month, next 6 months.
        $maturityByMonth = $months->map(fn ($m) => [
            'label' => $m->format('M y'),
            'amount' => (float) FixedDeposit::where('status', 'Active')
                ->whereDate('maturity_date', '>=', $m->copy()->startOfMonth())->whereDate('maturity_date', '<=', $m->copy()->endOfMonth())->sum('principal_amount'),
            'count' => FixedDeposit::where('status', 'Active')
                ->whereDate('maturity_date', '>=', $m->copy()->startOfMonth())->whereDate('maturity_date', '<=', $m->copy()->endOfMonth())->count(),
        ])->values();

        $byProduct = FixedDeposit::where('fixed_deposits.status', 'Active')
            ->join('funding_products', 'funding_products.id', '=', 'fixed_deposits.funding_product_id')
            ->groupBy('funding_products.name')
            ->selectRaw('funding_products.name as label, SUM(fixed_deposits.principal_amount) as value')
            ->orderByDesc('value')->get()
            ->map(fn ($r) => ['label' => $r->label, 'value' => (float) $r->value])->values();

        return [
            'interest_due_as_of' => $todayStr,
            'interest_due_total' => round($dueItems->sum('amount'), 2),
            'interest_due_count' => $dueItems->count(),
            'interest_due_overdue_count' => $dueItems->where('due_date', '<', $todayStr)->count(),
            'interest_due_items' => $dueItems,
            'interest_by_month' => $interestByMonth,
            'maturity_by_month' => $maturityByMonth,
            'portfolio_by_product' => $byProduct,
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
