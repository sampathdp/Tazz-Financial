<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FixedDeposit;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer base reports (Reports > Customer Reports). Same type-dispatch
 * shape as the other *ReportController classes — everything computed live
 * off customers/credit_facilities/fixed_deposits, nothing persisted here.
 */
class CustomerReportController extends Controller
{
    private const TYPES = ['summary', 'activity'];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'summary' => $this->summary(),
            'activity' => $this->activity($request),
        };
    }

    /** Total/active/new-this-month + a "repeat customer" figure (2+ loans across their lifetime). */
    private function summary(): array
    {
        $counts = Customer::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $total = (int) $counts->sum();

        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = now()->startOfMonth();
        $newThisMonth = Customer::where('created_at', '>=', $monthStart)->count();
        $newLastMonth = Customer::whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->count();

        $customersWithLoanCounts = Loan::select('customer_id', DB::raw('count(*) as loan_count'))
            ->groupBy('customer_id')->pluck('loan_count', 'customer_id');
        $borrowerCount = $customersWithLoanCounts->count();
        $repeatCount = $customersWithLoanCounts->filter(fn ($c) => $c >= 2)->count();

        return [
            'asOf' => now()->toDateString(),
            'totalCustomers' => $total,
            'activeCustomers' => (int) ($counts['Active'] ?? 0),
            'inactiveCustomers' => (int) ($counts['Inactive'] ?? 0),
            'blacklistedCustomers' => (int) ($counts['Blacklisted'] ?? 0),
            'newThisMonth' => $newThisMonth,
            'newLastMonth' => $newLastMonth,
            'growthPct' => $newLastMonth > 0 ? round((($newThisMonth - $newLastMonth) / $newLastMonth) * 100, 2) : null,
            'borrowerCount' => $borrowerCount,
            'repeatCustomerCount' => $repeatCount,
            'repeatCustomerPct' => $borrowerCount > 0 ? round(($repeatCount / $borrowerCount) * 100, 2) : 0.0,
        ];
    }

    /** Per-customer loan/FD activity — how many facilities, how much borrowed/deposited. */
    private function activity(Request $request)
    {
        $loanAgg = Loan::select('customer_id')
            ->selectRaw('count(*) as loan_count')
            ->selectRaw("sum(case when status = 'Active' then 1 else 0 end) as active_loan_count")
            ->selectRaw('sum(principal_amount) as total_borrowed')
            ->selectRaw("sum(case when status = 'Active' then outstanding_balance_cache else 0 end) as total_outstanding")
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $fdAgg = FixedDeposit::select('customer_id')
            ->selectRaw('count(*) as fd_count')
            ->selectRaw("sum(case when status = 'Active' then principal_amount else 0 end) as total_deposited")
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        return Customer::query()
            ->when($request->query('branchId'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->with('branch:id,name,code')
            ->orderBy('full_name')
            ->get(['id', 'code', 'full_name', 'branch_id', 'status', 'created_at'])
            ->map(function (Customer $c) use ($loanAgg, $fdAgg) {
                $loans = $loanAgg->get($c->id);
                $fds = $fdAgg->get($c->id);

                return [
                    'id' => $c->id, 'code' => $c->code, 'fullName' => $c->full_name,
                    'branch' => $c->branch ? ['id' => $c->branch->id, 'name' => $c->branch->name, 'code' => $c->branch->code] : null,
                    'status' => $c->status, 'joinedAt' => $c->created_at,
                    'loanCount' => (int) ($loans->loan_count ?? 0),
                    'activeLoanCount' => (int) ($loans->active_loan_count ?? 0),
                    'totalBorrowed' => round((float) ($loans->total_borrowed ?? 0), 2),
                    'totalOutstanding' => round((float) ($loans->total_outstanding ?? 0), 2),
                    'fdCount' => (int) ($fds->fd_count ?? 0),
                    'totalDeposited' => round((float) ($fds->total_deposited ?? 0), 2),
                ];
            })->values();
    }
}
