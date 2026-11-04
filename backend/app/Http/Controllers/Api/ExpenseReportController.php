<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Support\CurrentBranch;
use App\Support\ReportExport;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Expense reports (Reports > Expense Reports). Same type-dispatch shape as
 * the other *ReportController classes, reading straight off expenses/
 * expense_categories/vendors — nothing persisted here.
 */
class ExpenseReportController extends Controller
{
    private const TYPES = ['summary', 'list', 'by-vendor'];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'summary' => $this->summary($request),
            'list' => $this->list($request),
            'by-vendor' => $this->byVendor($request),
        };
    }

    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        [$title, $sections, $meta] = match ($type) {
            'summary' => $this->summaryPdf($request),
            'list' => $this->listPdf($request),
            'by-vendor' => $this->byVendorPdf($request),
        };

        return ReportExport::pdf($title, $sections, null, $meta)->download(ReportExport::filename($type));
    }

    private function summaryPdf(Request $request): array
    {
        $d = $this->summary($request);
        $metricRows = [
            ['metric' => 'Total Amount (Approved/Paid)', 'value' => number_format($d['totalAmount'], 2)],
            ['metric' => 'Paid Amount', 'value' => number_format($d['paidAmount'], 2)],
            ['metric' => 'Pending Approval Count', 'value' => $d['pendingApprovalCount']],
            ['metric' => 'Pending Approval Amount', 'value' => number_format($d['pendingApprovalAmount'], 2)],
        ];
        $sections = [
            ['title' => 'Summary', 'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']], 'rows' => $metricRows],
            [
                'title' => 'By Category', 'columns' => [['key' => 'name', 'label' => 'Category'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']],
                'rows' => $d['byCategory']->all(), 'emptyText' => 'No expenses in this range',
            ],
        ];

        return ['Expense Summary', $sections, "Period: {$d['from']} to {$d['to']}"];
    }

    private function listPdf(Request $request): array
    {
        $expenses = $this->list($request);
        $columns = [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'code', 'label' => 'Code'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'category', 'label' => 'Category'],
            ['key' => 'vendor', 'label' => 'Vendor'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'align' => 'center'], ['key' => 'requester', 'label' => 'Requested By'],
        ];
        $mapped = $expenses->map(fn (Expense $e) => [
            'date' => $e->expense_date?->format('Y-m-d') ?? '—', 'code' => $e->code, 'branch' => $e->branch?->name ?? '—',
            'category' => $e->category?->name ?? '—', 'vendor' => $e->vendor?->name ?? '—', 'amount' => (float) $e->amount,
            'status' => $e->status, 'requester' => $e->requester?->name ?? '—',
        ]);
        $sections = [['columns' => $columns, 'rows' => $mapped->all(), 'totalRow' => ['branch' => 'Total', 'amount' => $mapped->sum('amount')], 'emptyText' => 'No expenses in this range']];

        return ['Expense List', $sections, $this->periodMeta($request)];
    }

    private function byVendorPdf(Request $request): array
    {
        $rows = $this->byVendor($request);
        $columns = [['key' => 'vendorName', 'label' => 'Vendor'], ['key' => 'expenseCount', 'label' => 'Expense Count', 'align' => 'right', 'format' => 'number'], ['key' => 'totalAmount', 'label' => 'Total Amount', 'align' => 'right', 'format' => 'money']];
        $sections = [['columns' => $columns, 'rows' => $rows->all(), 'totalRow' => ['vendorName' => 'Total', 'totalAmount' => $rows->sum('totalAmount')], 'emptyText' => 'No vendor expenses in this range']];

        return ['Expenses by Vendor', $sections, $this->periodMeta($request)];
    }

    private function periodMeta(Request $request): string
    {
        [$from, $to] = $this->periodBounds($request);

        return 'Period: ' . $from->toDateString() . ' to ' . $to->toDateString();
    }

    private function summary(Request $request): array
    {
        [$from, $to] = $this->periodBounds($request);

        $expenses = CurrentBranch::applyScope(Expense::with('category:id,name')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]))
            ->get();

        // A refundable deposit is only a cost to the extent it is forfeited.
        $cost = fn ($e) => $e->is_refundable ? (float) $e->forfeited_amount : (float) $e->amount;
        $approvedOrPaid = $expenses->whereIn('status', ['Approved', 'Paid']);
        $pending = $expenses->where('status', 'Pending');

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'totalAmount' => round((float) $approvedOrPaid->sum($cost), 2),
            'paidAmount' => round((float) $expenses->where('status', 'Paid')->sum($cost), 2),
            'pendingApprovalCount' => $pending->count(),
            'pendingApprovalAmount' => round((float) $pending->sum('amount'), 2),
            'byCategory' => $approvedOrPaid->groupBy(fn ($e) => $e->category?->name ?? 'Uncategorized')
                ->map(fn ($g, $name) => ['name' => $name, 'amount' => round((float) $g->sum($cost), 2)])
                ->sortByDesc('amount')->values(),
        ];
    }

    private function list(Request $request)
    {
        [$from, $to] = $this->periodBounds($request);

        return CurrentBranch::applyScope(Expense::with(['branch:id,name,code', 'category:id,name', 'vendor:id,name', 'requester:id,name', 'approver:id,name'])
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('categoryId'), fn ($q, $c) => $q->where('expense_category_id', $c)))
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->get();
    }

    private function byVendor(Request $request)
    {
        [$from, $to] = $this->periodBounds($request);

        return CurrentBranch::applyScope(Expense::with('vendor:id,name')
            ->whereNotNull('vendor_id')
            ->whereIn('status', ['Approved', 'Paid'])
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]))
            ->get()
            ->groupBy('vendor_id')
            ->map(fn ($g) => [
                'vendorId' => $g->first()->vendor_id,
                'vendorName' => $g->first()->vendor?->name ?? '—',
                'expenseCount' => $g->count(),
                'totalAmount' => round((float) $g->sum('amount'), 2),
            ])
            ->sortByDesc('totalAmount')->values();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodBounds(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }
}
