<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FdInterestPayment;
use App\Models\FixedDeposit;
use App\Support\CurrentBranch;
use App\Support\ReportExport;
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

    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        [$title, $sections, $meta] = match ($type) {
            'summary' => $this->summaryPdf(),
            'directory' => $this->directoryPdf($request),
        };

        return ReportExport::pdf($title, $sections, null, $meta)->download(ReportExport::filename($type));
    }

    private function summaryPdf(): array
    {
        $d = $this->summary();
        $rows = [
            ['metric' => 'Investor Count', 'value' => $d['investorCount']],
            ['metric' => 'Active Investor Count', 'value' => $d['activeInvestorCount']],
            ['metric' => 'Total Invested', 'value' => number_format($d['totalInvested'], 2)],
            ['metric' => 'Average Investment', 'value' => number_format($d['avgInvestment'], 2)],
            ['metric' => 'New Investors This Month', 'value' => $d['newInvestorsThisMonth']],
        ];
        $sections = [['columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']], 'rows' => $rows]];

        return ['Investor Summary', $sections, 'As of ' . $d['asOf']];
    }

    private function directoryPdf(Request $request): array
    {
        $rows = $this->directory($request);
        $columns = [
            ['key' => 'code', 'label' => 'Code'], ['key' => 'fullName', 'label' => 'Name'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'phone', 'label' => 'Phone'],
            ['key' => 'fdCount', 'label' => 'FDs', 'align' => 'right', 'format' => 'number'], ['key' => 'activeFdCount', 'label' => 'Active FDs', 'align' => 'right', 'format' => 'number'],
            ['key' => 'totalPrincipal', 'label' => 'Total Principal', 'align' => 'right', 'format' => 'money'], ['key' => 'totalInterestPaid', 'label' => 'Interest Paid', 'align' => 'right', 'format' => 'money'],
        ];
        $mapped = $rows->map(fn ($r) => [
            'code' => $r['code'], 'fullName' => $r['fullName'], 'branch' => $r['branch']['name'] ?? '—', 'phone' => $r['phone'] ?? '—',
            'fdCount' => $r['fdCount'], 'activeFdCount' => $r['activeFdCount'], 'totalPrincipal' => $r['totalPrincipal'], 'totalInterestPaid' => $r['totalInterestPaid'],
        ]);
        $totalRow = ['fullName' => 'Total', 'totalPrincipal' => $mapped->sum('totalPrincipal'), 'totalInterestPaid' => $mapped->sum('totalInterestPaid')];
        $sections = [['columns' => $columns, 'rows' => $mapped->all(), 'totalRow' => $totalRow, 'emptyText' => 'No investors found']];

        return ['Investor Directory', $sections, null];
    }

    private function summary(): array
    {
        $investorCount = CurrentBranch::applyScope(FixedDeposit::query())->distinct()->count('customer_id');
        $activeInvestorCount = CurrentBranch::applyScope(FixedDeposit::where('status', 'Active'))->distinct()->count('customer_id');
        $totalInvested = (float) CurrentBranch::applyScope(FixedDeposit::where('status', 'Active'))->sum('principal_amount');

        $monthStart = now()->startOfMonth();
        // First-ever FD per customer, so a top-up from an existing investor
        // doesn't count as a "new" one.
        $firstFdDates = CurrentBranch::applyScope(FixedDeposit::select('customer_id', DB::raw('min(created_at) as first_at')))
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
        $fdQuery = FixedDeposit::select('customer_id')
            ->selectRaw('count(*) as fd_count')
            ->selectRaw("sum(case when status = 'Active' then 1 else 0 end) as active_fd_count")
            ->selectRaw("sum(case when status = 'Active' then principal_amount else 0 end) as total_principal")
            ->selectRaw('max(created_at) as last_deposit_at')
            ->groupBy('customer_id');
        $fdAgg = CurrentBranch::applyScope($fdQuery)->get()->keyBy('customer_id');

        $interestQuery = FdInterestPayment::join('fixed_deposits', 'fixed_deposits.id', '=', 'fd_interest_payments.fixed_deposit_id')
            ->where('fd_interest_payments.status', 'Paid')
            ->select('fixed_deposits.customer_id')
            ->selectRaw('sum(fd_interest_payments.amount) as total_interest_paid')
            ->groupBy('fixed_deposits.customer_id');
        $interestPaid = CurrentBranch::applyScope($interestQuery, 'fixed_deposits.branch_id')->pluck('total_interest_paid', 'customer_id');

        // Customer already carries BelongsToBranch — no manual branch filter needed.
        return Customer::query()
            ->whereIn('id', $fdAgg->keys())
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
