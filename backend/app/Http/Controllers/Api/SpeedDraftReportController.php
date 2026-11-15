<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpeedDraft;
use App\Support\CurrentBranch;
use App\Support\ReportExport;
use App\Support\SpeedDraftReports;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Speed Draft reports (Reports > Speed Draft Reports). Same single-route,
 * allow-listed-type shape as LoanReportController, but every type returns the
 * same generic payload so one screen/PDF/Excel path renders them all:
 *
 *   { title, meta, tiles: [{label, value, format, tone}], chart: {title, data}|null,
 *     sections: [{title, columns, rows, totalRow?, emptyText?}] }
 *
 * Live figures come from SpeedDraftReports (-> SpeedDraftSummary), the same
 * source as the Speed Draft detail screen and the dashboard.
 */
class SpeedDraftReportController extends Controller
{
    private const TYPES = ['portfolio-summary', 'par-aging', 'arrears', 'disbursement', 'closed', 'collections', 'maturity', 'draft-detail'];

    public function show(Request $request, string $type)
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        return $this->build($request, $type);
    }

    public function exportPdf(Request $request, string $type)
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $r = $this->build($request, $type);

        $summary = [];
        foreach ($r['tiles'] as $t) {
            $summary[] = ['metric' => $t['label'], 'value' => $this->fmt($t['value'], $t['format'] ?? null)];
        }
        $sections = [];
        if ($summary) {
            $sections[] = ['title' => 'Summary', 'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']], 'rows' => $summary];
        }
        foreach ($r['sections'] as $s) {
            $sections[] = $s;
        }

        return ReportExport::pdf($r['title'], $sections, null, $r['meta'])->download(ReportExport::filename('speed-draft-' . $type));
    }

    private function fmt($value, ?string $format): string
    {
        return match ($format) {
            'money' => number_format((float) $value, 2),
            'percent' => $value === null ? '—' : number_format((float) $value, 2) . '%',
            default => (string) ($value ?? '—'),
        };
    }

    private function build(Request $request, string $type): array
    {
        return match ($type) {
            'portfolio-summary' => $this->portfolioSummary($request),
            'par-aging' => $this->parAging($request),
            'arrears' => $this->arrears($request),
            'disbursement' => $this->disbursement($request),
            'closed' => $this->closed($request),
            'collections' => $this->collections($request),
            'maturity' => $this->maturity($request),
            'draft-detail' => $this->draftDetail($request),
        };
    }

    /* ----------------------------- helpers ----------------------------- */

    private function typeId(Request $request): ?int
    {
        $v = $request->input('speed_draft_type_id', $request->input('speedDraftTypeId'));

        return $v ? (int) $v : null;
    }

    private function from(Request $request, ?Carbon $default = null): Carbon
    {
        return $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : ($default ?? now()->startOfMonth());
    }

    private function to(Request $request, ?Carbon $default = null): Carbon
    {
        return $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : ($default ?? now()->endOfDay());
    }

    private function period(Carbon $from, Carbon $to): string
    {
        return 'Period: ' . $from->toDateString() . ' to ' . $to->toDateString();
    }

    private function tile(string $label, $value, ?string $format = null, ?string $tone = null, ?string $icon = null): array
    {
        return ['label' => $label, 'value' => $value, 'format' => $format, 'tone' => $tone, 'icon' => $icon];
    }

    private function byType(Request $request)
    {
        $typeId = $this->typeId($request);

        return fn ($q) => $q->when($typeId, fn ($q2) => $q2->whereHas('speedDraftDetail', fn ($q3) => $q3->where('speed_draft_type_id', $typeId)));
    }

    private function listRelations(): array
    {
        return ['customer:id,full_name,code', 'branch:id,name,code', 'speedDraftDetail.speedDraftType:id,name,code', 'speedDraftDetail.loanOfficer:id,name'];
    }

    private function sum(Collection $rows, string $key): float
    {
        return round((float) $rows->sum($key), 2);
    }

    /* ----------------------------- reports ----------------------------- */

    private function portfolioSummary(Request $request): array
    {
        $scope = $this->byType($request);
        $counts = $scope(SpeedDraft::query())->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $active = SpeedDraftReports::activeRows($this->typeId($request));

        $monthStart = now()->startOfMonth();
        $lastStart = now()->subMonthNoOverflow()->startOfMonth();
        $thisMonth = (float) $scope(SpeedDraft::whereNotNull('disbursed_at')->where('disbursed_at', '>=', $monthStart))->sum('disbursed_amount');
        $thisMonthCount = $scope(SpeedDraft::whereNotNull('disbursed_at')->where('disbursed_at', '>=', $monthStart))->count();
        $lastMonth = (float) $scope(SpeedDraft::whereNotNull('disbursed_at')->whereBetween('disbursed_at', [$lastStart, $monthStart]))->sum('disbursed_amount');
        $growth = $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 2) : null;

        $byType = $active->groupBy('type')->map(fn ($g, $name) => [
            'type' => $name, 'count' => $g->count(), 'principal' => $this->sum($g, 'principal'),
            'outstandingCapital' => $this->sum($g, 'outstandingCapital'), 'outstandingInterest' => $this->sum($g, 'outstandingInterest'),
            'penaltyDue' => $this->sum($g, 'penaltyDue'), 'receivable' => $this->sum($g, 'receivable'),
        ])->sortByDesc('outstandingCapital')->values();

        return [
            'title' => 'Speed Draft Portfolio Summary',
            'meta' => 'As of ' . now()->toDateString(),
            'tiles' => [
                $this->tile('Total Drafts', (int) $counts->sum(), 'number', 'blue', 'coins'),
                $this->tile('Active Drafts', $active->count(), 'number', 'green', 'check'),
                $this->tile('Pending Drafts', (int) ($counts['Pending'] ?? 0), 'number', 'amber', 'clock'),
                $this->tile('Closed Drafts', (int) ($counts['Closed'] ?? 0), 'number', 'blue', 'check'),
                $this->tile('Outstanding Capital', $this->sum($active, 'outstandingCapital'), 'money', 'blue', 'wallet'),
                $this->tile('Outstanding Interest', $this->sum($active, 'outstandingInterest'), 'money', 'amber', 'pie'),
                $this->tile('Penalty Due', $this->sum($active, 'penaltyDue'), 'money', 'red', 'alert'),
                $this->tile('Charges Due', $this->sum($active, 'outstandingCharges'), 'money', 'amber', 'tag'),
                $this->tile('Total Receivable', $this->sum($active, 'receivable'), 'money', 'blue', 'wallet'),
                $this->tile('Avg Draft Size', $active->count() ? round($this->sum($active, 'outstandingCapital') / $active->count(), 2) : 0, 'money', 'blue', 'target'),
                $this->tile('Disbursed This Month', round($thisMonth, 2), 'money', 'green', 'upload'),
                $this->tile('Disbursed Last Month', round($lastMonth, 2), 'money', 'blue', 'upload'),
                $this->tile('Disbursement Growth %', $growth, 'percent', $growth === null || $growth >= 0 ? 'green' : 'red', 'arrowUp'),
            ],
            'chart' => ['title' => 'Outstanding Capital by Speed Draft Type', 'kind' => 'bar', 'data' => $byType->map(fn ($r) => ['label' => $r['type'], 'value' => $r['outstandingCapital']])->all()],
            'sections' => [[
                'title' => 'Active Portfolio by Type',
                'columns' => [
                    ['key' => 'type', 'label' => 'Type'], ['key' => 'count', 'label' => 'Drafts', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'principal', 'label' => 'Original Capital', 'align' => 'right', 'format' => 'money'], ['key' => 'outstandingCapital', 'label' => 'Outstanding Capital', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'outstandingInterest', 'label' => 'Outstanding Interest', 'align' => 'right', 'format' => 'money'], ['key' => 'penaltyDue', 'label' => 'Penalty', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'receivable', 'label' => 'Total Receivable', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => $byType->all(),
                'totalRow' => ['type' => 'Total', 'count' => $byType->sum('count'), 'principal' => $byType->sum('principal'), 'outstandingCapital' => $byType->sum('outstandingCapital'), 'outstandingInterest' => $byType->sum('outstandingInterest'), 'penaltyDue' => $byType->sum('penaltyDue'), 'receivable' => $byType->sum('receivable')],
                'emptyText' => 'No active Speed Drafts',
            ]],
        ];
    }

    private function parAging(Request $request): array
    {
        $rows = SpeedDraftReports::activeRows($this->typeId($request));
        $total = $this->sum($rows, 'outstandingCapital');

        $buckets = collect(SpeedDraftReports::BUCKETS)->map(function ($b) use ($rows, $total) {
            $g = $rows->filter(function ($r) use ($b) {
                $d = $r['daysOverdue'];

                return $b['min'] === null ? $d <= 0 : ($d >= $b['min'] && ($b['max'] === null || $d <= $b['max']));
            });
            $cap = $this->sum($g, 'outstandingCapital');

            return [
                'label' => $b['label'], 'count' => $g->count(), 'capital' => $cap, 'pct' => $total > 0 ? round($cap / $total * 100, 2) : 0.0,
                'overdueInterest' => $this->sum($g, 'overdueInterest'), 'penaltyDue' => $this->sum($g, 'penaltyDue'),
            ];
        });
        $atRisk30 = (float) $buckets->slice(2)->sum('capital');
        $atRisk60 = (float) $buckets->slice(3)->sum('capital');
        $npl = (float) $buckets->last()['capital'];
        $pct = fn (float $v) => $total > 0 ? round($v / $total * 100, 2) : 0.0;

        return [
            'title' => 'Speed Draft PAR / NPL Aging',
            'meta' => 'As of ' . now()->toDateString(),
            'tiles' => [
                $this->tile('Active Capital Outstanding', $total, 'money', 'blue', 'wallet'),
                $this->tile('Drafts In Arrears', $rows->where('daysOverdue', '>', 0)->count(), 'number', 'amber', 'alert'),
                $this->tile('PAR 30 %', $pct($atRisk30), 'percent', $pct($atRisk30) > 5 ? 'red' : 'green', 'alert'),
                $this->tile('PAR 60 %', $pct($atRisk60), 'percent', $pct($atRisk60) > 5 ? 'red' : 'green', 'alert'),
                $this->tile('NPL % (90+ days)', $pct($npl), 'percent', $pct($npl) > 5 ? 'red' : 'green', 'shield'),
                $this->tile('NPL Amount', round($npl, 2), 'money', 'red', 'coins'),
            ],
            'chart' => ['title' => 'Capital at Risk — Aging Buckets', 'kind' => 'bar', 'color' => 'bad', 'data' => $buckets->map(fn ($b) => ['label' => str_replace([' days', ' (NPL)', ' (not overdue)'], ['d', '', ''], $b['label']), 'value' => $b['capital']])->all()],
            'sections' => [[
                'title' => 'Aging Buckets',
                'columns' => [
                    ['key' => 'label', 'label' => 'Bucket'], ['key' => 'count', 'label' => 'Drafts', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'capital', 'label' => 'Outstanding Capital', 'align' => 'right', 'format' => 'money'], ['key' => 'pct', 'label' => '% of Portfolio', 'align' => 'right', 'format' => 'percent'],
                    ['key' => 'overdueInterest', 'label' => 'Overdue Interest', 'align' => 'right', 'format' => 'money'], ['key' => 'penaltyDue', 'label' => 'Penalty Due', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => $buckets->all(),
                'totalRow' => ['label' => 'Total', 'count' => $buckets->sum('count'), 'capital' => $buckets->sum('capital'), 'overdueInterest' => $buckets->sum('overdueInterest'), 'penaltyDue' => $buckets->sum('penaltyDue')],
            ]],
        ];
    }

    private function arrears(Request $request): array
    {
        $rows = SpeedDraftReports::activeRows($this->typeId($request))->where('daysOverdue', '>', 0)->sortByDesc('daysOverdue')->values();
        $byOfficer = $rows->groupBy('officer')->map(fn ($g, $n) => ['label' => $n, 'value' => round((float) $g->sum('overdueInterest') + (float) $g->sum('penaltyDue'), 2)])->sortByDesc('value')->take(8)->values();

        return [
            'title' => 'Speed Draft Arrears',
            'meta' => 'As of ' . now()->toDateString(),
            'tiles' => [
                $this->tile('Overdue Drafts', $rows->count(), 'number', $rows->count() ? 'red' : 'green', 'alert'),
                $this->tile('Overdue Installments', (int) $rows->sum('overdueInstallments'), 'number', 'amber', 'list'),
                $this->tile('Overdue Interest', $this->sum($rows, 'overdueInterest'), 'money', 'red', 'wallet'),
                $this->tile('Penalty Due', $this->sum($rows, 'penaltyDue'), 'money', 'amber', 'coins'),
                $this->tile('Capital At Risk', $this->sum($rows, 'outstandingCapital'), 'money', 'red', 'target'),
                $this->tile('Total Receivable', $this->sum($rows, 'receivable'), 'money', 'amber', 'wallet'),
            ],
            'chart' => ['title' => 'Arrears by Officer (top 8)', 'kind' => 'bar', 'color' => 'bad', 'data' => $byOfficer->all()],
            'sections' => [[
                'columns' => [
                    ['key' => 'code', 'label' => 'Draft'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'type', 'label' => 'Type'], ['key' => 'officer', 'label' => 'Officer'],
                    ['key' => 'daysOverdue', 'label' => 'Days Overdue', 'align' => 'right', 'format' => 'number'], ['key' => 'overdueInstallments', 'label' => 'Installments', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'overdueInterest', 'label' => 'Overdue Interest', 'align' => 'right', 'format' => 'money'], ['key' => 'penaltyDue', 'label' => 'Penalty', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'outstandingCapital', 'label' => 'Capital', 'align' => 'right', 'format' => 'money'], ['key' => 'receivable', 'label' => 'Total Receivable', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => $rows->all(),
                'totalRow' => ['customer' => 'Total', 'overdueInstallments' => $rows->sum('overdueInstallments'), 'overdueInterest' => $rows->sum('overdueInterest'), 'penaltyDue' => $rows->sum('penaltyDue'), 'outstandingCapital' => $rows->sum('outstandingCapital'), 'receivable' => $rows->sum('receivable')],
                'emptyText' => 'No Speed Drafts in arrears',
            ]],
        ];
    }

    private function disbursement(Request $request): array
    {
        $from = $this->from($request);
        $to = $this->to($request);
        $drafts = SpeedDraft::with($this->listRelations())
            ->whereNotNull('disbursed_at')->whereBetween('disbursed_at', [$from, $to])
            ->tap($this->byType($request))->orderByDesc('disbursed_at')->get();

        $rows = $drafts->map(fn (SpeedDraft $d) => [
            'code' => $d->code, 'customer' => $d->customer?->full_name ?? '—', 'branch' => $d->branch?->name ?? '—',
            'type' => $d->speedDraftDetail?->speedDraftType?->name ?? '—', 'officer' => $d->speedDraftDetail?->loanOfficer?->name ?? '—',
            'principal' => (float) $d->principal_amount, 'disbursedAmount' => (float) ($d->disbursed_amount ?? $d->principal_amount),
            'ratePercent' => (float) $d->interest_rate_percent, 'termMonths' => (int) $d->term_months,
            'disbursedAt' => $d->disbursed_at?->toDateString(), 'maturityDate' => $d->maturity_date?->toDateString(),
            'method' => $d->speedDraftDetail?->disbursement_method ?? '—', 'status' => $d->status,
        ]);
        $total = $this->sum($rows, 'disbursedAmount');
        $byType = $rows->groupBy('type')->map(fn ($g, $n) => ['label' => $n, 'value' => $this->sum($g, 'disbursedAmount')])->values();

        return [
            'title' => 'Speed Draft Disbursement',
            'meta' => $this->period($from, $to),
            'tiles' => [
                $this->tile('Drafts Disbursed', $rows->count(), 'number', 'blue', 'upload'),
                $this->tile('Total Disbursed', $total, 'money', 'green', 'wallet'),
                $this->tile('Average Size', $rows->count() ? round($total / $rows->count(), 2) : 0, 'money', 'blue', 'target'),
            ],
            'chart' => ['title' => 'Disbursed by Speed Draft Type', 'kind' => 'bar', 'data' => $byType->all()],
            'sections' => [[
                'columns' => [
                    ['key' => 'code', 'label' => 'Draft'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'type', 'label' => 'Type'], ['key' => 'officer', 'label' => 'Officer'],
                    ['key' => 'principal', 'label' => 'Capital', 'align' => 'right', 'format' => 'money'], ['key' => 'disbursedAmount', 'label' => 'Disbursed', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'ratePercent', 'label' => 'Rate %', 'align' => 'right', 'format' => 'percent'], ['key' => 'termMonths', 'label' => 'Months', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'disbursedAt', 'label' => 'Disbursed On'], ['key' => 'maturityDate', 'label' => 'Maturity'], ['key' => 'method', 'label' => 'Method'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
                ],
                'rows' => $rows->all(),
                'totalRow' => ['customer' => 'Total', 'principal' => $rows->sum('principal'), 'disbursedAmount' => $rows->sum('disbursedAmount')],
                'emptyText' => 'No disbursements in this range',
            ]],
        ];
    }

    private function closed(Request $request): array
    {
        $from = $this->from($request);
        $to = $this->to($request);
        $drafts = SpeedDraft::with($this->listRelations())
            ->withSum(['payments as paid_total' => fn ($q) => $q->where('status', 'Active')], 'amount')
            ->where('status', 'Closed')->whereBetween('closed_at', [$from, $to])
            ->tap($this->byType($request))->orderByDesc('closed_at')->get();

        $rows = $drafts->map(fn (SpeedDraft $d) => [
            'code' => $d->code, 'customer' => $d->customer?->full_name ?? '—', 'branch' => $d->branch?->name ?? '—',
            'type' => $d->speedDraftDetail?->speedDraftType?->name ?? '—',
            'principal' => (float) $d->principal_amount, 'totalPaid' => (float) ($d->paid_total ?? 0),
            'interestEarned' => round(max(0, (float) ($d->paid_total ?? 0) - (float) $d->principal_amount), 2),
            'disbursedAt' => $d->disbursed_at?->toDateString(), 'closedAt' => $d->closed_at?->toDateString(),
            'daysRun' => $d->disbursed_at && $d->closed_at ? (int) $d->disbursed_at->diffInDays($d->closed_at) : null,
        ]);

        return [
            'title' => 'Closed Speed Drafts',
            'meta' => $this->period($from, $to),
            'tiles' => [
                $this->tile('Drafts Closed', $rows->count(), 'number', 'blue', 'check'),
                $this->tile('Capital Settled', $this->sum($rows, 'principal'), 'money', 'blue', 'wallet'),
                $this->tile('Total Collected', $this->sum($rows, 'totalPaid'), 'money', 'green', 'receipt'),
                $this->tile('Interest & Charges Earned', $this->sum($rows, 'interestEarned'), 'money', 'green', 'chart'),
            ],
            'chart' => null,
            'sections' => [[
                'columns' => [
                    ['key' => 'code', 'label' => 'Draft'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'type', 'label' => 'Type'],
                    ['key' => 'principal', 'label' => 'Capital', 'align' => 'right', 'format' => 'money'], ['key' => 'totalPaid', 'label' => 'Total Collected', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'interestEarned', 'label' => 'Interest & Charges', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'disbursedAt', 'label' => 'Disbursed'], ['key' => 'closedAt', 'label' => 'Closed'], ['key' => 'daysRun', 'label' => 'Days Run', 'align' => 'right', 'format' => 'number'],
                ],
                'rows' => $rows->all(),
                'totalRow' => ['customer' => 'Total', 'principal' => $rows->sum('principal'), 'totalPaid' => $rows->sum('totalPaid'), 'interestEarned' => $rows->sum('interestEarned')],
                'emptyText' => 'No Speed Drafts closed in this range',
            ]],
        ];
    }

    private function collections(Request $request): array
    {
        $from = $this->from($request);
        $to = $this->to($request);
        $typeId = $this->typeId($request);

        $q = DB::table('facility_payments as p')
            ->join('credit_facilities as cf', 'cf.id', '=', 'p.credit_facility_id')
            ->leftJoin('customers as c', 'c.id', '=', 'cf.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.received_by')
            ->where('cf.facility_type', 'SpeedDraft')->whereNull('cf.deleted_at')
            ->where('p.status', 'Active')->where('p.approval_status', 'Approved')
            ->where(fn ($w) => $w->whereNull('p.clearance_status')->orWhere('p.clearance_status', 'Realized'))
            ->whereBetween('p.paid_at', [$from, $to])
            ->when($typeId, fn ($w) => $w->whereIn('cf.id', fn ($s) => $s->select('credit_facility_id')->from('speed_draft_facility_details')->where('speed_draft_type_id', $typeId)));
        $payments = CurrentBranch::applyScope($q, 'cf.branch_id')
            ->orderBy('p.paid_at')
            ->get(['p.id', 'p.code', 'p.paid_at', 'p.amount', 'p.payment_type', 'p.payment_method', 'cf.code as draft_code', 'c.full_name as customer', 'u.name as received_by']);

        $rows = $payments->map(fn ($p) => [
            'paidAt' => substr((string) $p->paid_at, 0, 10), 'receipt' => $p->code, 'draft' => $p->draft_code, 'customer' => $p->customer ?? '—',
            'paymentType' => $p->payment_type, 'method' => $p->payment_method, 'amount' => (float) $p->amount, 'receivedBy' => $p->received_by ?? '—',
        ]);
        $sumType = fn (string $t) => round((float) $rows->where('paymentType', $t)->sum('amount'), 2);
        $byTypeRows = collect(['Interest', 'CapitalReduction', 'Settlement', 'Charges'])->map(fn ($t) => [
            'type' => ['CapitalReduction' => 'Capital Reduction', 'Settlement' => 'Full Settlement'][$t] ?? $t,
            'count' => $rows->where('paymentType', $t)->count(), 'amount' => $sumType($t),
        ]);
        $byMethod = $rows->groupBy('method')->map(fn ($g, $m) => ['method' => $m, 'count' => $g->count(), 'amount' => $this->sum($g, 'amount')])->values();
        $daily = $rows->groupBy('paidAt')->map(fn ($g, $d) => ['label' => substr($d, 5), 'value' => $this->sum($g, 'amount')])->values();

        return [
            'title' => 'Speed Draft Collections',
            'meta' => $this->period($from, $to),
            'tiles' => [
                $this->tile('Total Collected', $this->sum($rows, 'amount'), 'money', 'green', 'wallet'),
                $this->tile('Receipts', $rows->count(), 'number', 'blue', 'receipt'),
                $this->tile('Interest', $sumType('Interest'), 'money', 'blue', 'pie'),
                $this->tile('Capital Reduction', $sumType('CapitalReduction'), 'money', 'blue', 'coins'),
                $this->tile('Full Settlements', $sumType('Settlement'), 'money', 'green', 'check'),
                $this->tile('Charges', $sumType('Charges'), 'money', 'amber', 'tag'),
            ],
            'chart' => $daily->count() > 1 ? ['title' => 'Daily Collections', 'kind' => 'bar', 'data' => $daily->all()] : null,
            'sections' => [
                ['title' => 'By Payment Type', 'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'count', 'label' => 'Receipts', 'align' => 'right', 'format' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']], 'rows' => $byTypeRows->all(), 'totalRow' => ['type' => 'Total', 'count' => $byTypeRows->sum('count'), 'amount' => $byTypeRows->sum('amount')]],
                ['title' => 'By Method', 'columns' => [['key' => 'method', 'label' => 'Method'], ['key' => 'count', 'label' => 'Receipts', 'align' => 'right', 'format' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']], 'rows' => $byMethod->all(), 'emptyText' => 'No collections in this range'],
                ['title' => 'Receipts', 'columns' => [
                    ['key' => 'paidAt', 'label' => 'Date'], ['key' => 'receipt', 'label' => 'Receipt'], ['key' => 'draft', 'label' => 'Draft'], ['key' => 'customer', 'label' => 'Customer'],
                    ['key' => 'paymentType', 'label' => 'Type'], ['key' => 'method', 'label' => 'Method'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'receivedBy', 'label' => 'Received By'],
                ], 'rows' => $rows->all(), 'totalRow' => ['customer' => 'Total', 'amount' => $rows->sum('amount')], 'emptyText' => 'No receipts in this range'],
            ],
        ];
    }

    private function maturity(Request $request): array
    {
        $from = $this->from($request, now()->startOfDay());
        $to = $this->to($request, now()->addDays(30)->endOfDay());
        $today = now()->startOfDay();
        $rows = SpeedDraftReports::activeRows($this->typeId($request))
            ->filter(fn ($r) => $r['maturityDate'] && $r['maturityDate'] >= $from->toDateString() && $r['maturityDate'] <= $to->toDateString())
            ->map(fn ($r) => $r + ['daysToMaturity' => (int) $today->diffInDays(Carbon::parse($r['maturityDate']), false)])
            ->sortBy('maturityDate')->values();

        return [
            'title' => 'Speed Draft Maturity Schedule',
            'meta' => $this->period($from, $to),
            'tiles' => [
                $this->tile('Drafts Maturing', $rows->count(), 'number', 'amber', 'clock'),
                $this->tile('Capital Falling Due', $this->sum($rows, 'outstandingCapital'), 'money', 'blue', 'wallet'),
                $this->tile('Interest Outstanding', $this->sum($rows, 'outstandingInterest'), 'money', 'amber', 'pie'),
                $this->tile('Total To Collect', $this->sum($rows, 'receivable'), 'money', 'green', 'target'),
            ],
            'chart' => null,
            'sections' => [[
                'columns' => [
                    ['key' => 'code', 'label' => 'Draft'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'type', 'label' => 'Type'],
                    ['key' => 'maturityDate', 'label' => 'Maturity'], ['key' => 'daysToMaturity', 'label' => 'Days Left', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'outstandingCapital', 'label' => 'Capital', 'align' => 'right', 'format' => 'money'], ['key' => 'outstandingInterest', 'label' => 'Interest', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'penaltyDue', 'label' => 'Penalty', 'align' => 'right', 'format' => 'money'], ['key' => 'receivable', 'label' => 'Total To Collect', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => $rows->all(),
                'totalRow' => ['customer' => 'Total', 'outstandingCapital' => $rows->sum('outstandingCapital'), 'outstandingInterest' => $rows->sum('outstandingInterest'), 'penaltyDue' => $rows->sum('penaltyDue'), 'receivable' => $rows->sum('receivable')],
                'emptyText' => 'No Speed Drafts mature in this range',
            ]],
        ];
    }

    private function draftDetail(Request $request): array
    {
        $status = $request->filled('status') ? (string) $request->status : null;
        $drafts = SpeedDraft::with([...$this->listRelations(), 'approver:id,name'])
            ->withSum(['payments as paid_total' => fn ($q) => $q->where('status', 'Active')], 'amount')
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->tap($this->byType($request))->orderByDesc('id')->get();
        $live = SpeedDraftReports::activeRows($this->typeId($request))->keyBy('id');

        $rows = $drafts->map(function (SpeedDraft $d) use ($live) {
            $l = $live->get($d->id);

            return [
                'code' => $d->code, 'status' => $d->status, 'customer' => $d->customer?->full_name ?? '—', 'customerCode' => $d->customer?->code,
                'branch' => $d->branch?->name ?? '—', 'type' => $d->speedDraftDetail?->speedDraftType?->name ?? '—', 'officer' => $d->speedDraftDetail?->loanOfficer?->name ?? '—',
                'principal' => (float) $d->principal_amount, 'ratePercent' => (float) $d->interest_rate_percent, 'ratePeriod' => $d->rate_period, 'termMonths' => (int) $d->term_months,
                'interestMethod' => $d->speedDraftDetail?->interest_method, 'disbursedAt' => $d->disbursed_at?->toDateString(), 'maturityDate' => $d->maturity_date?->toDateString(),
                'closedAt' => $d->closed_at?->toDateString(), 'approvedBy' => $d->approver?->name ?? '—', 'totalPaid' => (float) ($d->paid_total ?? 0),
                'outstandingCapital' => $l['outstandingCapital'] ?? (float) $d->outstanding_balance_cache, 'outstandingInterest' => $l['outstandingInterest'] ?? 0.0,
                'penaltyDue' => $l['penaltyDue'] ?? 0.0, 'daysOverdue' => $l['daysOverdue'] ?? 0, 'receivable' => $l['receivable'] ?? 0.0,
            ];
        });

        return [
            'title' => 'Speed Draft Detail Report',
            'meta' => 'As of ' . now()->toDateString() . ($status ? " · Status: {$status}" : ''),
            'tiles' => [
                $this->tile('Drafts', $rows->count(), 'number', 'blue', 'list'),
                $this->tile('Total Capital', $this->sum($rows, 'principal'), 'money', 'blue', 'coins'),
                $this->tile('Total Collected', $this->sum($rows, 'totalPaid'), 'money', 'green', 'receipt'),
                $this->tile('Total Receivable', $this->sum($rows, 'receivable'), 'money', 'amber', 'wallet'),
            ],
            'chart' => null,
            'sections' => [[
                'columns' => [
                    ['key' => 'code', 'label' => 'Draft'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'branch', 'label' => 'Branch'],
                    ['key' => 'type', 'label' => 'Type'], ['key' => 'officer', 'label' => 'Officer'], ['key' => 'principal', 'label' => 'Capital', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'ratePercent', 'label' => 'Rate %', 'align' => 'right', 'format' => 'percent'], ['key' => 'ratePeriod', 'label' => 'Rate Period'], ['key' => 'termMonths', 'label' => 'Months', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'interestMethod', 'label' => 'Interest Method'], ['key' => 'disbursedAt', 'label' => 'Disbursed'], ['key' => 'maturityDate', 'label' => 'Maturity'], ['key' => 'closedAt', 'label' => 'Closed'],
                    ['key' => 'approvedBy', 'label' => 'Approved By'], ['key' => 'totalPaid', 'label' => 'Total Collected', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'outstandingCapital', 'label' => 'Outstanding Capital', 'align' => 'right', 'format' => 'money'], ['key' => 'outstandingInterest', 'label' => 'Outstanding Interest', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'penaltyDue', 'label' => 'Penalty', 'align' => 'right', 'format' => 'money'], ['key' => 'daysOverdue', 'label' => 'Days Overdue', 'align' => 'right', 'format' => 'number'],
                    ['key' => 'receivable', 'label' => 'Total Receivable', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => $rows->all(),
                'totalRow' => ['customer' => 'Total', 'principal' => $rows->sum('principal'), 'totalPaid' => $rows->sum('totalPaid'), 'outstandingCapital' => $rows->sum('outstandingCapital'), 'outstandingInterest' => $rows->sum('outstandingInterest'), 'penaltyDue' => $rows->sum('penaltyDue'), 'receivable' => $rows->sum('receivable')],
                'emptyText' => 'No Speed Drafts',
            ]],
        ];
    }
}
