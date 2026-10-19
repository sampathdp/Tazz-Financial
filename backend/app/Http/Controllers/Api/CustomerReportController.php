<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FixedDeposit;
use App\Models\Loan;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer base reports (Reports > Customer Reports). Same type-dispatch
 * shape as the other *ReportController classes — everything computed live
 * off customers/credit_facilities/fixed_deposits, nothing persisted here.
 */
class CustomerReportController extends Controller
{
    private const TYPES = ['summary', 'activity', 'blacklisted'];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'summary' => $this->summary(),
            'activity' => $this->activity($request),
            'blacklisted' => $this->blacklisted(),
        };
    }

    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        [$title, $sections, $meta] = match ($type) {
            'summary' => $this->summaryPdf(),
            'activity' => $this->activityPdf($request),
            'blacklisted' => $this->blacklistedPdf(),
        };

        return ReportExport::pdf($title, $sections, null, $meta)->download(ReportExport::filename($type));
    }

    private function blacklistedPdf(): array
    {
        $rows = $this->blacklisted()->map(fn ($r) => [
            'code' => $r['code'], 'fullName' => $r['fullName'], 'nicNo' => $r['nicNo'] ?? '—', 'branch' => $r['branch']['name'] ?? '—',
            'reason' => $r['reason'] ?? '—', 'note' => $r['note'] ?? '—', 'blacklistedAt' => substr((string) $r['blacklistedAt'], 0, 10) ?: '—', 'blacklistedBy' => $r['blacklistedBy'] ?? '—',
            'outstanding' => $r['totalOutstanding'],
        ]);
        $columns = [
            ['key' => 'code', 'label' => 'Code'], ['key' => 'fullName', 'label' => 'Name'], ['key' => 'nicNo', 'label' => 'NIC'], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'reason', 'label' => 'Reason'], ['key' => 'note', 'label' => 'Note'], ['key' => 'blacklistedAt', 'label' => 'Date'], ['key' => 'blacklistedBy', 'label' => 'By'],
            ['key' => 'outstanding', 'label' => 'Outstanding', 'align' => 'right', 'format' => 'money'],
        ];
        $sections = [['columns' => $columns, 'rows' => $rows->all(), 'totalRow' => ['fullName' => 'Total', 'outstanding' => $rows->sum('outstanding')], 'emptyText' => 'No blacklisted customers']];

        return ['Blacklisted Customers', $sections, 'As of ' . now()->toDateString()];
    }

    /** Every blacklisted customer with the reason, who flagged them and any balance still owed. */
    private function blacklisted()
    {
        $customers = Customer::where('status', 'Blacklisted')->with('branch:id,name,code')->orderByDesc('blacklisted_at')->get();
        $owed = Loan::whereIn('customer_id', $customers->pluck('id'))->where('status', 'Active')
            ->select('customer_id')->selectRaw('sum(outstanding_balance_cache) as total_outstanding')
            ->groupBy('customer_id')->pluck('total_outstanding', 'customer_id');
        $users = User::whereIn('id', $customers->pluck('blacklisted_by')->filter())->pluck('name', 'id');

        return $customers->map(fn (Customer $c) => [
            'id' => $c->id, 'code' => $c->code, 'fullName' => $c->full_name, 'nicNo' => $c->nic_no, 'phone' => $c->phone,
            'branch' => $c->branch ? ['id' => $c->branch->id, 'name' => $c->branch->name, 'code' => $c->branch->code] : null,
            'reason' => $c->blacklist_reason, 'note' => $c->blacklist_note, 'blacklistedAt' => $c->blacklisted_at, 'blacklistedBy' => $users[$c->blacklisted_by] ?? null,
            'totalOutstanding' => round((float) ($owed[$c->id] ?? 0), 2),
        ])->values();
    }

    private function summaryPdf(): array
    {
        $d = $this->summary();
        $rows = [
            ['metric' => 'Total Customers', 'value' => $d['totalCustomers']],
            ['metric' => 'Active Customers', 'value' => $d['activeCustomers']],
            ['metric' => 'Inactive Customers', 'value' => $d['inactiveCustomers']],
            ['metric' => 'Blacklisted Customers', 'value' => $d['blacklistedCustomers']],
            ['metric' => 'New This Month', 'value' => $d['newThisMonth']],
            ['metric' => 'New Last Month', 'value' => $d['newLastMonth']],
            ['metric' => 'Growth %', 'value' => $d['growthPct'] === null ? '—' : number_format($d['growthPct'], 2) . '%'],
            ['metric' => 'Borrower Count', 'value' => $d['borrowerCount']],
            ['metric' => 'Repeat Customer Count', 'value' => $d['repeatCustomerCount']],
            ['metric' => 'Repeat Customer %', 'value' => number_format($d['repeatCustomerPct'], 2) . '%'],
        ];
        $sections = [['columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']], 'rows' => $rows]];

        return ['Customer Summary', $sections, 'As of ' . $d['asOf']];
    }

    private function activityPdf(Request $request): array
    {
        $rows = $this->activity($request);
        $columns = [
            ['key' => 'code', 'label' => 'Code'], ['key' => 'fullName', 'label' => 'Name'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'loanCount', 'label' => 'Loans', 'align' => 'right', 'format' => 'number'], ['key' => 'totalBorrowed', 'label' => 'Total Borrowed', 'align' => 'right', 'format' => 'money'],
            ['key' => 'totalOutstanding', 'label' => 'Outstanding', 'align' => 'right', 'format' => 'money'], ['key' => 'fdCount', 'label' => 'FDs', 'align' => 'right', 'format' => 'number'],
            ['key' => 'totalDeposited', 'label' => 'Total Deposited', 'align' => 'right', 'format' => 'money'],
        ];
        $mapped = $rows->map(fn ($r) => [
            'code' => $r['code'], 'fullName' => $r['fullName'], 'branch' => $r['branch']['name'] ?? '—', 'status' => $r['status'],
            'loanCount' => $r['loanCount'], 'totalBorrowed' => $r['totalBorrowed'], 'totalOutstanding' => $r['totalOutstanding'],
            'fdCount' => $r['fdCount'], 'totalDeposited' => $r['totalDeposited'],
        ]);
        $totalRow = ['fullName' => 'Total', 'totalBorrowed' => $mapped->sum('totalBorrowed'), 'totalOutstanding' => $mapped->sum('totalOutstanding'), 'totalDeposited' => $mapped->sum('totalDeposited')];
        $sections = [['columns' => $columns, 'rows' => $mapped->all(), 'totalRow' => $totalRow, 'emptyText' => 'No customers found']];

        return ['Customer Activity', $sections, null];
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

        $fdQuery = FixedDeposit::select('customer_id')
            ->selectRaw('count(*) as fd_count')
            ->selectRaw("sum(case when status = 'Active' then principal_amount else 0 end) as total_deposited")
            ->groupBy('customer_id');
        $fdAgg = CurrentBranch::applyScope($fdQuery)->get()->keyBy('customer_id');

        // Customer already carries BelongsToBranch — its own global scope handles both
        // the forced own-branch restriction and the optional ?branch_id= filter for
        // all-branch roles, so no manual branch filter is needed here.
        return Customer::query()
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
