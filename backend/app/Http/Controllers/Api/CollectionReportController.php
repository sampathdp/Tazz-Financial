<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacilityPayment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Loan collection reports (Reports > Collection Reports). Same type-dispatch
 * shape as LoanReportController. Scoped to Loan-facing collections only
 * (facility_type = 'Loan' on credit_facilities) — Speed Draft has its own
 * payment flow and isn't a "recovery officer chases arrears" product the
 * way a Loan is, so it's excluded from due/collected/officer figures here.
 */
class CollectionReportController extends Controller
{
    private const TYPES = ['daily-cash', 'collection-summary', 'officer-performance', 'trend'];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'daily-cash' => $this->dailyCash($request),
            'collection-summary' => $this->collectionSummary($request),
            'officer-performance' => $this->officerPerformance($request),
            'trend' => $this->trend($request),
        };
    }

    /** Payments actually banked/received on a given day (default today). */
    private function dailyCash(Request $request): array
    {
        $date = $request->filled('date') ? Carbon::parse($request->date)->startOfDay() : now()->startOfDay();

        $payments = FacilityPayment::whereHas('facility')
            ->where('status', 'Active')->where('approval_status', 'Approved')
            ->whereDate('paid_at', $date)
            ->with(['facility:id,code,customer_id,branch_id', 'facility.customer:id,full_name,code', 'branch:id,name,code', 'receiver:id,name'])
            ->orderByDesc('paid_at')->get();

        return [
            'date' => $date->toDateString(),
            'totalAmount' => round((float) $payments->sum('amount'), 2),
            'count' => $payments->count(),
            'byMethod' => $payments->groupBy('payment_method')->map(fn ($g) => round((float) $g->sum('amount'), 2)),
            'payments' => $payments,
        ];
    }

    /** Due vs collected for a period — the Collection Rate %/Recovery figures. */
    private function collectionSummary(Request $request): array
    {
        [$from, $to] = $this->periodBounds($request);

        $due = (float) DB::table('facility_schedules as fs')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fs.credit_facility_id')
            ->where('cf.facility_type', 'Loan')
            ->whereBetween('fs.due_date', [$from->toDateString(), $to->toDateString()])
            ->sum('fs.total_due');

        $collected = (float) DB::table('facility_payments as fp')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fp.credit_facility_id')
            ->where('cf.facility_type', 'Loan')
            ->where('fp.status', 'Active')->where('fp.approval_status', 'Approved')
            ->whereBetween('fp.paid_at', [$from, $to])
            ->sum('fp.amount');

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'dueAmount' => round($due, 2),
            'collectedAmount' => round($collected, 2),
            'outstandingAmount' => round(max(0, $due - $collected), 2),
            'collectionRatePct' => $due > 0 ? round(($collected / $due) * 100, 2) : null,
        ];
    }

    /** Per recovery officer: due vs collected in the period, so branch managers can rank recovery performance. */
    private function officerPerformance(Request $request)
    {
        [$from, $to] = $this->periodBounds($request);

        $due = DB::table('facility_schedules as fs')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fs.credit_facility_id')
            ->join('loan_facility_details as lfd', 'lfd.credit_facility_id', '=', 'cf.id')
            ->join('users as u', 'u.id', '=', 'lfd.recovery_officer_id')
            ->where('cf.facility_type', 'Loan')
            ->whereBetween('fs.due_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.id as officer_id, u.name as officer_name, count(distinct cf.id) as loan_count, sum(fs.total_due) as due_amount')
            ->get()->keyBy('officer_id');

        $collected = DB::table('facility_payments as fp')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fp.credit_facility_id')
            ->join('loan_facility_details as lfd', 'lfd.credit_facility_id', '=', 'cf.id')
            ->where('cf.facility_type', 'Loan')
            ->where('fp.status', 'Active')->where('fp.approval_status', 'Approved')
            ->whereBetween('fp.paid_at', [$from, $to])
            ->whereNotNull('lfd.recovery_officer_id')
            ->groupBy('lfd.recovery_officer_id')
            ->selectRaw('lfd.recovery_officer_id as officer_id, sum(fp.amount) as collected_amount')
            ->get()->keyBy('officer_id');

        $officerIds = $due->keys()->merge($collected->keys())->unique();

        return $officerIds->map(function ($id) use ($due, $collected) {
            $dueAmount = (float) ($due->get($id)->due_amount ?? 0);
            $collectedAmount = (float) ($collected->get($id)->collected_amount ?? 0);

            return [
                'officerId' => $id,
                'officerName' => $due->get($id)->officer_name ?? '—',
                'loanCount' => (int) ($due->get($id)->loan_count ?? 0),
                'dueAmount' => round($dueAmount, 2),
                'collectedAmount' => round($collectedAmount, 2),
                'collectionRatePct' => $dueAmount > 0 ? round(($collectedAmount / $dueAmount) * 100, 2) : null,
            ];
        })->sortByDesc('collectedAmount')->values();
    }

    /** Collected-amount trend bucketed daily/weekly/monthly, most recent N buckets. */
    private function trend(Request $request): array
    {
        $bucket = in_array($request->query('bucket'), ['daily', 'weekly', 'monthly'], true) ? $request->query('bucket') : 'daily';
        $periods = max(1, min(90, (int) $request->query('periods', $bucket === 'daily' ? 30 : ($bucket === 'weekly' ? 12 : 12))));

        $groupExpr = match ($bucket) {
            'weekly' => "DATE_FORMAT(fp.paid_at, '%x-W%v')",
            'monthly' => "DATE_FORMAT(fp.paid_at, '%Y-%m')",
            default => 'DATE(fp.paid_at)',
        };
        $since = match ($bucket) {
            'weekly' => now()->subWeeks($periods)->startOfWeek(),
            'monthly' => now()->subMonths($periods)->startOfMonth(),
            default => now()->subDays($periods)->startOfDay(),
        };

        $rows = DB::table('facility_payments as fp')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fp.credit_facility_id')
            ->where('cf.facility_type', 'Loan')
            ->where('fp.status', 'Active')->where('fp.approval_status', 'Approved')
            ->where('fp.paid_at', '>=', $since)
            ->selectRaw("{$groupExpr} as bucket_key, sum(fp.amount) as collected_amount, count(*) as payment_count")
            ->groupBy('bucket_key')->orderBy('bucket_key')->get();

        return ['bucket' => $bucket, 'since' => $since->toDateString(), 'rows' => $rows];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodBounds(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }
}
