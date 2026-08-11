<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
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

    private function summary(Request $request): array
    {
        [$from, $to] = $this->periodBounds($request);

        $expenses = Expense::with('category:id,name')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $approvedOrPaid = $expenses->whereIn('status', ['Approved', 'Paid']);
        $pending = $expenses->where('status', 'Pending');

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'totalAmount' => round((float) $approvedOrPaid->sum('amount'), 2),
            'paidAmount' => round((float) $expenses->where('status', 'Paid')->sum('amount'), 2),
            'pendingApprovalCount' => $pending->count(),
            'pendingApprovalAmount' => round((float) $pending->sum('amount'), 2),
            'byCategory' => $approvedOrPaid->groupBy(fn ($e) => $e->category?->name ?? 'Uncategorized')
                ->map(fn ($g, $name) => ['name' => $name, 'amount' => round((float) $g->sum('amount'), 2)])
                ->sortByDesc('amount')->values(),
        ];
    }

    private function list(Request $request)
    {
        [$from, $to] = $this->periodBounds($request);

        return Expense::with(['branch:id,name,code', 'category:id,name', 'vendor:id,name', 'requester:id,name', 'approver:id,name'])
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('categoryId'), fn ($q, $c) => $q->where('expense_category_id', $c))
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->get();
    }

    private function byVendor(Request $request)
    {
        [$from, $to] = $this->periodBounds($request);

        return Expense::with('vendor:id,name')
            ->whereNotNull('vendor_id')
            ->whereIn('status', ['Approved', 'Paid'])
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
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
