<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FdInterestPayment;
use App\Models\FixedDeposit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Investor-centric reports (Reports > Investor Reports) — customers who hold
 * a fixed deposit, viewed by investor rather than by product/FD (that's
 * FundingReportController's job). Same type-dispatch shape as the other
 * *ReportController classes.
 */
class InvestorReportController extends Controller
{
    private const TYPES = ['summary', 'directory'];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'summary' => $this->summary(),
            'directory' => $this->directory($request),
        };
    }

    private function summary(): array
    {
        $investorCount = FixedDeposit::distinct()->count('customer_id');
        $activeInvestorCount = FixedDeposit::where('status', 'Active')->distinct()->count('customer_id');
        $totalInvested = (float) FixedDeposit::where('status', 'Active')->sum('principal_amount');

        $monthStart = now()->startOfMonth();
        // First-ever FD per customer, so a top-up from an existing investor
        // doesn't count as a "new" one.
        $firstFdDates = FixedDeposit::select('customer_id', DB::raw('min(created_at) as first_at'))
            ->groupBy('customer_id')->pluck('first_at', 'customer_id');
        $newInvestorsThisMonth = $firstFdDates->filter(fn ($d) => $d >= $monthStart)->count();

        return [
            'asOf' => now()->toDateString(),
            'investorCount' => $investorCount,
            'activeInvestorCount' => $activeInvestorCount,
            'totalInvested' => round($totalInvested, 2),
            'avgInvestment' => $activeInvestorCount > 0 ? round($totalInvested / $activeInvestorCount, 2) : 0,
            'newInvestorsThisMonth' => $newInvestorsThisMonth,
        ];
    }

    /** Per-investor FD holdings, interest paid to date, and last activity. */
    private function directory(Request $request)
    {
        $fdAgg = FixedDeposit::select('customer_id')
            ->selectRaw('count(*) as fd_count')
            ->selectRaw("sum(case when status = 'Active' then 1 else 0 end) as active_fd_count")
            ->selectRaw("sum(case when status = 'Active' then principal_amount else 0 end) as total_principal")
            ->selectRaw('max(created_at) as last_deposit_at')
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $interestPaid = FdInterestPayment::join('fixed_deposits', 'fixed_deposits.id', '=', 'fd_interest_payments.fixed_deposit_id')
            ->where('fd_interest_payments.status', 'Paid')
            ->select('fixed_deposits.customer_id')
            ->selectRaw('sum(fd_interest_payments.amount) as total_interest_paid')
            ->groupBy('fixed_deposits.customer_id')->pluck('total_interest_paid', 'customer_id');

        return Customer::query()
            ->whereIn('id', $fdAgg->keys())
            ->when($request->query('branchId'), fn ($q, $b) => $q->where('branch_id', $b))
            ->with('branch:id,name,code')
            ->orderBy('full_name')
            ->get(['id', 'code', 'full_name', 'branch_id', 'phone'])
            ->map(function (Customer $c) use ($fdAgg, $interestPaid) {
                $fds = $fdAgg->get($c->id);

                return [
                    'id' => $c->id, 'code' => $c->code, 'fullName' => $c->full_name, 'phone' => $c->phone,
                    'branch' => $c->branch ? ['id' => $c->branch->id, 'name' => $c->branch->name, 'code' => $c->branch->code] : null,
                    'fdCount' => (int) ($fds->fd_count ?? 0),
                    'activeFdCount' => (int) ($fds->active_fd_count ?? 0),
                    'totalPrincipal' => round((float) ($fds->total_principal ?? 0), 2),
                    'totalInterestPaid' => round((float) ($interestPaid->get($c->id) ?? 0), 2),
                    'lastDepositAt' => $fds->last_deposit_at ?? null,
                ];
            })->values();
    }
}
