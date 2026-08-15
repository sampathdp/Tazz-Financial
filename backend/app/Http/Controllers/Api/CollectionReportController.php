<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacilityPayment;
use App\Support\CurrentBranch;
use App\Support\ReportExport;
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

    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        [$title, $sections, $meta] = match ($type) {
            'daily-cash' => $this->dailyCashPdf($request),
            'collection-summary' => $this->collectionSummaryPdf($request),
            'officer-performance' => $this->officerPerformancePdf($request),
            'trend' => $this->trendPdf($request),
        };

        return ReportExport::pdf($title, $sections, null, $meta)->download(ReportExport::filename($type));
    }

    private function dailyCashPdf(Request $request): array
    {
        $d = $this->dailyCash($request);
        $columns = [
            ['key' => 'receipt', 'label' => 'Receipt'], ['key' => 'loan', 'label' => 'Loan'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'method', 'label' => 'Method'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'],
            ['key' => 'receiver', 'label' => 'Received By'], ['key' => 'time', 'label' => 'Time'],
        ];
        $mapped = $d['payments']->map(fn ($p) => [
            'receipt' => $p->receipt_no ?: $p->code, 'loan' => $p->facility?->code ?? '—', 'customer' => $p->facility?->customer?->full_name ?? '—',
            'branch' => $p->branch?->name ?? '—', 'method' => $p->payment_method, 'amount' => (float) $p->amount,
            'receiver' => $p->receiver?->name ?? '—', 'time' => $p->paid_at?->format('H:i') ?? '—',
        ]);
        $sections = [['columns' => $columns, 'rows' => $mapped->all(), 'totalRow' => ['customer' => 'Total', 'amount' => $d['totalAmount']], 'emptyText' => 'No payments received on this date']];

        return ['Daily Cash Collection', $sections, 'Date: ' . $d['date'] . '  •  ' . $d['count'] . ' payment(s)'];
    }

    private function collectionSummaryPdf(Request $request): array
    {
        $d = $this->collectionSummary($request);
        $rows = [
            ['metric' => 'Due Amount', 'value' => number_format($d['dueAmount'], 2)],
            ['metric' => 'Collected Amount', 'value' => number_format($d['collectedAmount'], 2)],
            ['metric' => 'Outstanding Amount', 'value' => number_format($d['outstandingAmount'], 2)],
            ['metric' => 'Collection Rate %', 'value' => $d['collectionRatePct'] === null ? '—' : number_format($d['collectionRatePct'], 2) . '%'],
        ];
        $sections = [['columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']], 'rows' => $rows]];

        return ['Collection Summary', $sections, "Period: {$d['from']} to {$d['to']}"];
    }

    private function officerPerformancePdf(Request $request): array
    {
        $rows = $this->officerPerformance($request);
        $columns = [
            ['key' => 'officerName', 'label' => 'Officer'], ['key' => 'loanCount', 'label' => 'Loans', 'align' => 'right', 'format' => 'number'],
            ['key' => 'dueAmount', 'label' => 'Due Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'collectedAmount', 'label' => 'Collected Amount', 'align' => 'right', 'format' => 'money'],
            ['key' => 'collectionRatePct', 'label' => 'Collection Rate %', 'align' => 'right', 'format' => 'percent'],
        ];
        $mapped = $rows->map(fn ($r) => (array) $r);
        $totalRow = ['officerName' => 'Total', 'dueAmount' => $mapped->sum('dueAmount'), 'collectedAmount' => $mapped->sum('collectedAmount')];
        $sections = [['columns' => $columns, 'rows' => $mapped->all(), 'totalRow' => $totalRow, 'emptyText' => 'No collection activity in this range']];

        return ['Officer Performance', $sections, $this->periodMeta($request)];
    }

    private function trendPdf(Request $request): array
    {
        $d = $this->trend($request);
        $columns = [['key' => 'period', 'label' => 'Period'], ['key' => 'count', 'label' => 'Payments', 'align' => 'right', 'format' => 'number'], ['key' => 'amount', 'label' => 'Collected Amount', 'align' => 'right', 'format' => 'money']];
        $rows = collect($d['rows'])->map(fn ($r) => ['period' => $r->bucket_key, 'count' => (int) $r->payment_count, 'amount' => (float) $r->collected_amount]);
        $sections = [['columns' => $columns, 'rows' => $rows->all(), 'totalRow' => ['period' => 'Total', 'count' => $rows->sum('count'), 'amount' => $rows->sum('amount')], 'emptyText' => 'No collection activity in this range']];

        return ['Collection Trend (' . ucfirst($d['bucket']) . ')', $sections, 'Since: ' . $d['since']];
    }

    private function periodMeta(Request $request): string
    {
        [$from, $to] = $this->periodBounds($request);

        return 'Period: ' . $from->toDateString() . ' to ' . $to->toDateString();
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

        $dueQuery = DB::table('facility_schedules as fs')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fs.credit_facility_id')
            ->where('cf.facility_type', 'Loan')
            ->whereBetween('fs.due_date', [$from->toDateString(), $to->toDateString()]);
        $due = (float) CurrentBranch::applyScope($dueQuery, 'cf.branch_id')->sum('fs.total_due');

        $collectedQuery = DB::table('facility_payments as fp')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fp.credit_facility_id')
            ->where('cf.facility_type', 'Loan')
            ->where('fp.status', 'Active')->where('fp.approval_status', 'Approved')
            ->whereBetween('fp.paid_at', [$from, $to]);
        $collected = (float) CurrentBranch::applyScope($collectedQuery, 'cf.branch_id')->sum('fp.amount');

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

        $dueQuery = DB::table('facility_schedules as fs')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fs.credit_facility_id')
            ->join('loan_facility_details as lfd', 'lfd.credit_facility_id', '=', 'cf.id')
            ->join('users as u', 'u.id', '=', 'lfd.recovery_officer_id')
            ->where('cf.facility_type', 'Loan')
            ->whereBetween('fs.due_date', [$from->toDateString(), $to->toDateString()]);
        $due = CurrentBranch::applyScope($dueQuery, 'cf.branch_id')
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.id as officer_id, u.name as officer_name, count(distinct cf.id) as loan_count, sum(fs.total_due) as due_amount')
            ->get()->keyBy('officer_id');

        $collectedQuery = DB::table('facility_payments as fp')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fp.credit_facility_id')
            ->join('loan_facility_details as lfd', 'lfd.credit_facility_id', '=', 'cf.id')
            ->where('cf.facility_type', 'Loan')
            ->where('fp.status', 'Active')->where('fp.approval_status', 'Approved')
            ->whereBetween('fp.paid_at', [$from, $to])
            ->whereNotNull('lfd.recovery_officer_id');
        $collected = CurrentBranch::applyScope($collectedQuery, 'cf.branch_id')
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

        $rowsQuery = DB::table('facility_payments as fp')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fp.credit_facility_id')
            ->where('cf.facility_type', 'Loan')
            ->where('fp.status', 'Active')->where('fp.approval_status', 'Approved')
            ->where('fp.paid_at', '>=', $since);
        $rows = CurrentBranch::applyScope($rowsQuery, 'cf.branch_id')
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
