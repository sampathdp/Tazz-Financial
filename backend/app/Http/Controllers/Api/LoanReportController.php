<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Support\LoanSummary;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Loan portfolio reports (Reports > Loan Reports). Same type-dispatch shape
 * as FundingReportController/AccountingReportController: one route, an
 * allow-listed TYPES const, one private method per type, everything computed
 * live off credit_facilities/facility_schedules — nothing persisted here.
 */
class LoanReportController extends Controller
{
    private const TYPES = ['portfolio-summary', 'arrears', 'par-aging', 'disbursement', 'closed-loans'];

    private const LIST_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone',
        'branch:id,name,code',
        'loanDetail.loanType:id,name,code',
        'loanDetail.loanOfficer:id,name',
    ];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'portfolio-summary' => $this->portfolioSummary(),
            'arrears' => $this->arrears($request),
            'par-aging' => $this->parAging(),
            'disbursement' => $this->disbursement($request),
            'closed-loans' => $this->closedLoans($request),
        };
    }

    /** Loan Portfolio Ratios source data: counts, outstanding, avg size, disbursement growth. */
    private function portfolioSummary(): array
    {
        $counts = Loan::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $active = Loan::where('status', 'Active');
        $totalOutstanding = (clone $active)->sum('outstanding_balance_cache');
        $activeCount = (clone $active)->count();

        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = now()->startOfMonth();

        $thisMonth = Loan::whereNotNull('disbursed_at')->where('disbursed_at', '>=', $monthStart);
        $lastMonth = Loan::whereNotNull('disbursed_at')->whereBetween('disbursed_at', [$lastMonthStart, $lastMonthEnd]);
        $disbursedThisMonth = (clone $thisMonth)->sum('disbursed_amount');
        $disbursedLastMonth = (clone $lastMonth)->sum('disbursed_amount');

        return [
            'asOf' => now()->toDateString(),
            'totalLoans' => (int) $counts->sum(),
            'activeLoans' => $activeCount,
            'closedLoans' => (int) ($counts['Closed'] ?? 0),
            'pendingLoans' => (int) ($counts['Pending'] ?? 0),
            'writtenOffLoans' => (int) ($counts['WrittenOff'] ?? 0),
            'totalOutstandingBalance' => round((float) $totalOutstanding, 2),
            'avgLoanSize' => $activeCount > 0 ? round((float) $totalOutstanding / $activeCount, 2) : 0,
            'disbursedThisMonthCount' => (clone $thisMonth)->count(),
            'disbursedThisMonthAmount' => round((float) $disbursedThisMonth, 2),
            'disbursedLastMonthAmount' => round((float) $disbursedLastMonth, 2),
            'loanGrowthPct' => $disbursedLastMonth > 0
                ? round((((float) $disbursedThisMonth - (float) $disbursedLastMonth) / (float) $disbursedLastMonth) * 100, 2)
                : null,
        ];
    }

    private function arrears(Request $request)
    {
        $today = $request->filled('to') ? Carbon::parse($request->to)->startOfDay() : now()->startOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;

        return LoanSummary::overdueList($today, $from);
    }

    /** PAR 30/60/90 aging buckets + NPL% (90+ days), by outstanding balance. */
    private function parAging(): array
    {
        $today = now()->startOfDay();
        $overdue = LoanSummary::overdueList($today)->keyBy('id');

        $buckets = [
            'current' => ['label' => 'Current (not overdue)', 'min' => null, 'max' => null, 'count' => 0, 'amount' => 0.0],
            'par1_29' => ['label' => '1-29 days', 'min' => 1, 'max' => 29, 'count' => 0, 'amount' => 0.0],
            'par30_59' => ['label' => '30-59 days', 'min' => 30, 'max' => 59, 'count' => 0, 'amount' => 0.0],
            'par60_89' => ['label' => '60-89 days', 'min' => 60, 'max' => 89, 'count' => 0, 'amount' => 0.0],
            'par90_plus' => ['label' => '90+ days (NPL)', 'min' => 90, 'max' => null, 'count' => 0, 'amount' => 0.0],
        ];

        $totalPortfolio = 0.0;
        Loan::where('status', 'Active')->select('id', 'outstanding_balance_cache')
            ->chunk(500, function ($loans) use (&$buckets, &$totalPortfolio, $overdue) {
                foreach ($loans as $loan) {
                    $balance = (float) $loan->outstanding_balance_cache;
                    $totalPortfolio += $balance;
                    $daysOverdue = (int) ($overdue->get($loan->id)['daysOverdue'] ?? 0);

                    $key = match (true) {
                        $daysOverdue <= 0 => 'current',
                        $daysOverdue < 30 => 'par1_29',
                        $daysOverdue < 60 => 'par30_59',
                        $daysOverdue < 90 => 'par60_89',
                        default => 'par90_plus',
                    };
                    $buckets[$key]['count']++;
                    $buckets[$key]['amount'] += $balance;
                }
            });

        foreach ($buckets as &$b) {
            $b['amount'] = round($b['amount'], 2);
            $b['pct'] = $totalPortfolio > 0 ? round(($b['amount'] / $totalPortfolio) * 100, 2) : 0.0;
        }
        unset($b);

        $atRisk30 = $buckets['par30_59']['amount'] + $buckets['par60_89']['amount'] + $buckets['par90_plus']['amount'];
        $atRisk60 = $buckets['par60_89']['amount'] + $buckets['par90_plus']['amount'];
        $nplAmount = $buckets['par90_plus']['amount'];

        return [
            'asOf' => $today->toDateString(),
            'totalPortfolio' => round($totalPortfolio, 2),
            'buckets' => array_values($buckets),
            'par30Pct' => $totalPortfolio > 0 ? round(($atRisk30 / $totalPortfolio) * 100, 2) : 0.0,
            'par60Pct' => $totalPortfolio > 0 ? round(($atRisk60 / $totalPortfolio) * 100, 2) : 0.0,
            'nplPct' => $totalPortfolio > 0 ? round(($nplAmount / $totalPortfolio) * 100, 2) : 0.0,
            'nplAmount' => round($nplAmount, 2),
        ];
    }

    private function disbursement(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return Loan::with(self::LIST_RELATIONS)
            ->whereNotNull('disbursed_at')
            ->whereBetween('disbursed_at', [$from, $to])
            ->orderByDesc('disbursed_at')
            ->get();
    }

    private function closedLoans(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return Loan::with(self::LIST_RELATIONS)
            ->where('status', 'Closed')
            ->whereBetween('closed_at', [$from, $to])
            ->orderByDesc('closed_at')
            ->get();
    }
}
