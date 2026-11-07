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
    private const TYPES = ['daily-cash', 'collection-summary', 'officer-performance', 'trend', 'due-schedule'];

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
            'due-schedule' => $this->dueSchedule($request),
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
            'due-schedule' => $this->dueSchedulePdf($request),
        };

        return ReportExport::pdf($title, $sections, null, $meta)->download(ReportExport::filename($type));
    }

    private function dailyCashPdf(Request $request): array
    {
        $d = $this->dailyCash($request);
        $columns = [
            ['key' => 'receipt', 'label' => 'Receipt'], ['key' => 'loan', 'label' => 'Loan'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'method', 'label' => 'Method'],
            ['key' => 'principal', 'label' => 'Principal', 'align' => 'right', 'format' => 'money'], ['key' => 'interest', 'label' => 'Interest', 'align' => 'right', 'format' => 'money'],
            ['key' => 'penalty', 'label' => 'Penalty', 'align' => 'right', 'format' => 'money'], ['key' => 'charges', 'label' => 'Charges', 'align' => 'right', 'format' => 'money'],
            ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'],
            ['key' => 'receiver', 'label' => 'Received By'], ['key' => 'time', 'label' => 'Time'],
        ];
        $mapped = $d['payments']->map(fn ($p) => [
            'receipt' => $p->receipt_no ?: $p->code, 'loan' => $p->facility?->code ?? '—', 'customer' => $p->facility?->customer?->full_name ?? '—',
            'branch' => $p->branch?->name ?? '—', 'method' => $p->payment_method,
            'principal' => $p->principal_applied, 'interest' => $p->interest_applied, 'penalty' => $p->penalty_applied, 'charges' => $p->charges_applied,
            'amount' => (float) $p->amount,
            'receiver' => $p->receiver?->name ?? '—', 'time' => $p->paid_at?->format('H:i') ?? '—',
        ]);
        $totalRow = ['customer' => 'Total', 'principal' => $d['totalPrincipal'], 'interest' => $d['totalInterest'], 'penalty' => $d['totalPenalty'], 'charges' => $d['totalCharges'], 'amount' => $d['totalAmount']];
        $methodRows = $d['byMethod']->map(fn ($amt, $m) => ['name' => $m, 'amount' => $amt])->values()->all();
        $receiverRows = $d['byReceiver']->map(fn ($v, $n) => ['name' => $n, 'count' => $v['count'], 'amount' => $v['amount']])->values()->all();
        $sections = [
            ['columns' => $columns, 'rows' => $mapped->all(), 'totalRow' => $totalRow, 'emptyText' => 'No payments received on this date'],
            ['title' => 'By Payment Method', 'columns' => [['key' => 'name', 'label' => 'Method'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']], 'rows' => $methodRows],
            ['title' => 'By Receiver', 'columns' => [['key' => 'name', 'label' => 'Received By'], ['key' => 'count', 'label' => 'Payments', 'align' => 'right', 'format' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']], 'rows' => $receiverRows],
        ];

        return ['Daily Cash Collection', $sections, 'Date: ' . $d['date'] . '  •  ' . $d['count'] . ' payment(s)'];
    }

    private function collectionSummaryPdf(Request $request): array
    {
        $d = $this->collectionSummary($request);
        $n = fn ($v) => number_format($v, 2);
        $rows = [
            ['metric' => 'Loans With Installments Due', 'value' => (string) $d['loanCount']],
            ['metric' => 'Installments Due', 'value' => (string) $d['installmentCount']],
            ['metric' => 'Payments Received', 'value' => (string) $d['paymentCount']],
            ['metric' => 'Due Amount', 'value' => $n($d['dueAmount'])],
            ['metric' => '   Principal Due', 'value' => $n($d['dueBreakdown']['principal'])],
            ['metric' => '   Interest Due', 'value' => $n($d['dueBreakdown']['interest'])],
            ['metric' => '   Penalty Due', 'value' => $n($d['dueBreakdown']['penalty'])],
            ['metric' => 'Collected Amount', 'value' => $n($d['collectedAmount'])],
            ['metric' => '   Principal Collected', 'value' => $n($d['collectedBreakdown']['principal'])],
            ['metric' => '   Interest Collected', 'value' => $n($d['collectedBreakdown']['interest'])],
            ['metric' => '   Penalty Collected', 'value' => $n($d['collectedBreakdown']['penalty'])],
            ['metric' => 'Outstanding Amount', 'value' => $n($d['outstandingAmount'])],
            ['metric' => 'Collection Rate %', 'value' => $d['collectionRatePct'] === null ? '—' : $n($d['collectionRatePct']) . '%'],
        ];
        $sections = [
            ['columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']], 'rows' => $rows],
            ['title' => 'Collected by Payment Method', 'columns' => [['key' => 'method', 'label' => 'Method'], ['key' => 'count', 'label' => 'Payments', 'align' => 'right', 'format' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']], 'rows' => $d['byMethod']->all()],
        ];

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

    /** Payments actually banked/received in a date range (`from`/`to`, or a single `date`; default today). */
    private function dailyCash(Request $request): array
    {
        $single = $request->filled('date') ? Carbon::parse($request->date) : now();
        $from = ($request->filled('from') ? Carbon::parse($request->from) : $single)->startOfDay();
        $to = ($request->filled('to') ? Carbon::parse($request->to) : ($request->filled('from') ? now() : $single))->endOfDay();

        $payments = FacilityPayment::whereHas('facility')
            ->where('status', 'Active')->where('approval_status', 'Approved')
            ->where(fn ($q) => $q->whereNull('clearance_status')->orWhere('clearance_status', 'Realized'))
            ->whereBetween('paid_at', [$from, $to])
            ->with(['facility:id,code,customer_id,branch_id', 'facility.customer:id,full_name,code', 'branch:id,name,code', 'receiver:id,name', 'allocations', 'chargeAllocations'])
            ->orderByDesc('paid_at')->get();

        // Split each payment into what it settled: principal / interest / penalty / other charges.
        $payments->each(function ($p) {
            $p->setAttribute('principal_applied', round((float) $p->allocations->sum('principal_applied'), 2));
            $p->setAttribute('interest_applied', round((float) $p->allocations->sum('interest_applied'), 2));
            $p->setAttribute('penalty_applied', round((float) $p->allocations->sum('penalty_applied'), 2));
            $p->setAttribute('charges_applied', round((float) $p->chargeAllocations->sum('amount_applied'), 2));
            $p->makeHidden(['allocations', 'chargeAllocations']);
        });

        return [
            'date' => $from->toDateString() === $to->toDateString() ? $from->toDateString() : $from->toDateString() . ' to ' . $to->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totalAmount' => round((float) $payments->sum('amount'), 2),
            'count' => $payments->count(),
            'totalPrincipal' => round((float) $payments->sum('principal_applied'), 2),
            'totalInterest' => round((float) $payments->sum('interest_applied'), 2),
            'totalPenalty' => round((float) $payments->sum('penalty_applied'), 2),
            'totalCharges' => round((float) $payments->sum('charges_applied'), 2),
            'byMethod' => $payments->groupBy('payment_method')->map(fn ($g) => round((float) $g->sum('amount'), 2)),
            'byReceiver' => $payments->groupBy(fn ($p) => $p->receiver?->name ?? '—')->map(fn ($g) => [
                'count' => $g->count(), 'amount' => round((float) $g->sum('amount'), 2),
            ]),
            'byBranch' => $payments->groupBy(fn ($p) => $p->branch?->name ?? '—')->map(fn ($g) => [
                'count' => $g->count(), 'amount' => round((float) $g->sum('amount'), 2),
            ]),
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
            ->where(fn ($q) => $q->whereNull('fp.clearance_status')->orWhere('fp.clearance_status', 'Realized'))
            ->whereBetween('fp.paid_at', [$from, $to]);
        $collected = (float) CurrentBranch::applyScope($collectedQuery, 'cf.branch_id')->sum('fp.amount');

        // Collected split by what it settled (principal / interest / penalty).
        $splitQuery = DB::table('facility_payment_allocations as fpa')
            ->join('facility_payments as fp', 'fp.id', '=', 'fpa.facility_payment_id')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fp.credit_facility_id')
            ->where('cf.facility_type', 'Loan')
            ->where('fp.status', 'Active')->where('fp.approval_status', 'Approved')
            ->where(fn ($q) => $q->whereNull('fp.clearance_status')->orWhere('fp.clearance_status', 'Realized'))
            ->whereBetween('fp.paid_at', [$from, $to]);
        $split = CurrentBranch::applyScope($splitQuery, 'cf.branch_id')
            ->selectRaw('coalesce(sum(fpa.principal_applied),0) as p, coalesce(sum(fpa.interest_applied),0) as i, coalesce(sum(fpa.penalty_applied),0) as pen')
            ->first();

        // Due split by component.
        $dueSplitQuery = DB::table('facility_schedules as fs')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fs.credit_facility_id')
            ->where('cf.facility_type', 'Loan')
            ->whereBetween('fs.due_date', [$from->toDateString(), $to->toDateString()]);
        $dueSplit = CurrentBranch::applyScope($dueSplitQuery, 'cf.branch_id')
            ->selectRaw('coalesce(sum(fs.principal_due),0) as p, coalesce(sum(fs.interest_due),0) as i, coalesce(sum(fs.penalty_due),0) as pen, count(*) as installments, count(distinct cf.id) as loans')
            ->first();

        // Collected by payment method.
        $methodQuery = DB::table('facility_payments as fp')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fp.credit_facility_id')
            ->where('cf.facility_type', 'Loan')
            ->where('fp.status', 'Active')->where('fp.approval_status', 'Approved')
            ->where(fn ($q) => $q->whereNull('fp.clearance_status')->orWhere('fp.clearance_status', 'Realized'))
            ->whereBetween('fp.paid_at', [$from, $to]);
        $byMethod = CurrentBranch::applyScope($methodQuery, 'cf.branch_id')
            ->groupBy('fp.payment_method')
            ->selectRaw('fp.payment_method as method, count(*) as payment_count, sum(fp.amount) as amount')
            ->get()->map(fn ($r) => ['method' => $r->method, 'count' => (int) $r->payment_count, 'amount' => round((float) $r->amount, 2)])->values();

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'installmentCount' => (int) ($dueSplit->installments ?? 0),
            'loanCount' => (int) ($dueSplit->loans ?? 0),
            'paymentCount' => (int) $byMethod->sum('count'),
            'dueBreakdown' => ['principal' => round((float) ($dueSplit->p ?? 0), 2), 'interest' => round((float) ($dueSplit->i ?? 0), 2), 'penalty' => round((float) ($dueSplit->pen ?? 0), 2)],
            'collectedBreakdown' => [
                'principal' => round((float) ($split->p ?? 0), 2), 'interest' => round((float) ($split->i ?? 0), 2), 'penalty' => round((float) ($split->pen ?? 0), 2),
            ],
            'byMethod' => $byMethod,
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
            ->where(fn ($q) => $q->whereNull('fp.clearance_status')->orWhere('fp.clearance_status', 'Realized'))
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
            ->where(fn ($q) => $q->whereNull('fp.clearance_status')->orWhere('fp.clearance_status', 'Realized'))
            ->where('fp.paid_at', '>=', $since);
        $rows = CurrentBranch::applyScope($rowsQuery, 'cf.branch_id')
            ->selectRaw("{$groupExpr} as bucket_key, sum(fp.amount) as collected_amount, count(*) as payment_count")
            ->groupBy('bucket_key')->orderBy('bucket_key')->get();

        return ['bucket' => $bucket, 'since' => $since->toDateString(), 'rows' => $rows];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    /** Installments falling due in a date window: unpaid balance = total_due - paid_amount per schedule row, Active/Lapsed loans only. */
    private function dueWindow(Carbon $from, Carbon $to, bool $withRows): array
    {
        $q = DB::table('facility_schedules as fs')
            ->join('credit_facilities as cf', 'cf.id', '=', 'fs.credit_facility_id')
            ->join('customers as c', 'c.id', '=', 'cf.customer_id')
            ->where('cf.facility_type', 'Loan')
            ->whereIn('cf.status', ['Active', 'Lapsed'])
            ->whereBetween('fs.due_date', [$from->toDateString(), $to->toDateString()]);
        $q = CurrentBranch::applyScope($q, 'cf.branch_id');

        $totals = (clone $q)->selectRaw('coalesce(sum(fs.total_due),0) as due, coalesce(sum(least(fs.paid_amount, fs.total_due)),0) as paid, count(*) as installments, count(distinct cf.id) as loans')->first();
        $due = round((float) $totals->due, 2);
        $paid = round((float) $totals->paid, 2);
        $out = [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'dueAmount' => $due, 'paidAmount' => $paid, 'balanceDue' => round($due - $paid, 2),
            'installmentCount' => (int) $totals->installments, 'loanCount' => (int) $totals->loans,
        ];
        if ($withRows) {
            $out['rows'] = $q->groupBy('cf.id', 'cf.code', 'c.full_name', 'c.phone')
                ->orderBy('cf.code')
                ->selectRaw('cf.code as loan_code, c.full_name as customer, c.phone as phone, count(*) as installments, min(fs.due_date) as first_due, sum(fs.total_due) as due, sum(least(fs.paid_amount, fs.total_due)) as paid')
                ->get()->map(fn ($r) => [
                    'loanCode' => $r->loan_code, 'customer' => $r->customer, 'phone' => $r->phone, 'installments' => (int) $r->installments,
                    'firstDue' => substr((string) $r->first_due, 0, 10), 'dueAmount' => round((float) $r->due, 2),
                    'paidAmount' => round((float) $r->paid, 2), 'balanceDue' => round((float) $r->due - (float) $r->paid, 2),
                ])->values()->all();
        }

        return $out;
    }

    /** Weekly (Mon-Sun) and monthly due for the week/month containing `date` (default today); `rows` lists loans for `period` (week|month). */
    private function dueSchedule(Request $request): array
    {
        $ref = $request->filled('date') ? Carbon::parse($request->date) : now();
        $period = $request->input('period') === 'month' ? 'month' : 'week';
        $week = $this->dueWindow($ref->copy()->startOfWeek(), $ref->copy()->endOfWeek(), $period === 'week');
        $month = $this->dueWindow($ref->copy()->startOfMonth(), $ref->copy()->endOfMonth(), $period === 'month');
        $rows = $period === 'week' ? $week['rows'] : $month['rows'];
        unset($week['rows'], $month['rows']);

        return ['date' => $ref->toDateString(), 'period' => $period, 'week' => $week, 'month' => $month, 'rows' => $rows];
    }

    private function dueSchedulePdf(Request $request): array
    {
        $d = $this->dueSchedule($request);
        $label = $d['period'] === 'month' ? 'Monthly' : 'Weekly';
        $win = $d[$d['period']];
        $columns = [
            ['key' => 'loanCode', 'label' => 'Loan'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'phone', 'label' => 'Phone'],
            ['key' => 'installments', 'label' => 'Installments', 'align' => 'right', 'format' => 'number'], ['key' => 'firstDue', 'label' => 'First Due'],
            ['key' => 'dueAmount', 'label' => 'Due', 'align' => 'right', 'format' => 'money'], ['key' => 'paidAmount', 'label' => 'Paid', 'align' => 'right', 'format' => 'money'],
            ['key' => 'balanceDue', 'label' => 'Balance Due', 'align' => 'right', 'format' => 'money'],
        ];
        $rows = collect($d['rows']);
        $totalRow = ['customer' => 'Total', 'installments' => $rows->sum('installments'), 'dueAmount' => $rows->sum('dueAmount'), 'paidAmount' => $rows->sum('paidAmount'), 'balanceDue' => $rows->sum('balanceDue')];

        return ["{$label} Due Report", [['columns' => $columns, 'rows' => $d['rows'], 'totalRow' => $totalRow, 'emptyText' => 'Nothing due in this period']], "Period: {$win['from']} to {$win['to']}"];
    }

    private function periodBounds(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }
}
