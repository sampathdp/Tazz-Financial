<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Finance dashboard — cash control at a glance. Read-only live
 * aggregates over cash_accounts / bank_accounts / finance_requests /
 * finance_transactions, branch-scoped the same way every other report is
 * (CurrentBranch). A user with can_view_all_branches sees the company
 * picture and the per-branch breakdown; everyone else sees just their branch.
 */
class FinanceDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->toDateString();
        $staleDays = (int) $request->query('stale_days', 3);

        $cashQuery = CurrentBranch::applyScope(CashAccount::query()->where('status', 'Active'));
        $cashAccounts = $cashQuery->get(['id', 'code', 'name', 'branch_id', 'account_type', 'current_balance', 'responsible_user_id', 'last_reconciled_at']);

        $byType = $cashAccounts->groupBy('account_type')->map(fn ($g) => round((float) $g->sum('current_balance'), 2));
        $bankTotal = (float) CurrentBranch::applyScope(BankAccount::query()->where('status', 'Active'))->sum('current_balance');

        $unreconciled = $cashAccounts->filter(function ($a) use ($staleDays) {
            return $a->last_reconciled_at === null
                || \Illuminate\Support\Carbon::parse($a->last_reconciled_at)->diffInDays(now()) >= $staleDays;
        })->count();

        $pending = CurrentBranch::applyScope(FinanceRequest::query()->where('status', 'PendingApproval'));
        $ready = CurrentBranch::applyScope(FinanceRequest::query()->where('status', 'ReadyForPayment'));

        $txnToday = CurrentBranch::applyScope(FinanceTransaction::query()->whereDate('executed_at', $today)->where('status', '!=', 'Reversed'));

        return [
            'cash' => [
                'total' => round((float) $cashAccounts->sum('current_balance'), 2),
                'by_type' => $byType,
                'main' => round((float) $byType->only(['HeadOfficeMain', 'BranchMain'])->sum(), 2),
                'petty' => round((float) ($byType['PettyCash'] ?? 0), 2),
                'floats' => round((float) ($byType['CashierFloat'] ?? 0), 2),
                'in_transit' => round((float) ($byType['InTransit'] ?? 0), 2),
            ],
            'bank_total' => round($bankTotal, 2),
            'unreconciled_accounts' => $unreconciled,
            'requests' => [
                'pending_count' => (clone $pending)->count(),
                'pending_amount' => round((float) (clone $pending)->sum('amount'), 2),
                'ready_count' => (clone $ready)->count(),
                'ready_amount' => round((float) (clone $ready)->sum('amount'), 2),
            ],
            'today' => [
                'inflow' => round((float) (clone $txnToday)->where('direction', 'In')->sum('amount'), 2),
                'outflow' => round((float) (clone $txnToday)->where('direction', 'Out')->sum('amount'), 2),
                'disbursements' => round((float) (clone $txnToday)->where('txn_type', 'DISBURSEMENT')->sum('amount'), 2),
                'expense_payments' => round((float) (clone $txnToday)->where('txn_type', 'EXPENSE_PAYMENT')->sum('amount'), 2),
                'transfers' => round((float) (clone $txnToday)->where('txn_type', 'TRANSFER')->sum('amount'), 2),
                'collections' => round((float) (clone $txnToday)->where('txn_type', 'COLLECTION')->sum('amount'), 2),
            ],
            'accounts' => $cashAccounts->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'account_type' => $a->account_type,
                'branch_id' => $a->branch_id, 'current_balance' => round((float) $a->current_balance, 2),
                'last_reconciled_at' => $a->last_reconciled_at,
            ])->values(),
            'by_branch' => $this->byBranch(),
        ];
    }

    /** Per-branch cash + pending/ready totals — only meaningful for an all-branches user; a branch-scoped one gets a single row. */
    private function byBranch(): array
    {
        $cash = CurrentBranch::applyScope(DB::table('cash_accounts')->where('status', 'Active'), 'branch_id')
            ->select('branch_id', DB::raw('SUM(current_balance) as cash'))->groupBy('branch_id')->pluck('cash', 'branch_id');

        $pending = CurrentBranch::applyScope(DB::table('finance_requests')->where('status', 'PendingApproval'), 'branch_id')
            ->select('branch_id', DB::raw('COUNT(*) c'), DB::raw('SUM(amount) a'))->groupBy('branch_id')->get()->keyBy('branch_id');

        $names = DB::table('branches')->pluck('name', 'id');

        return $cash->keys()->merge($pending->keys())->unique()->filter()->map(fn ($id) => [
            'branch_id' => (int) $id,
            'branch' => $names[$id] ?? "Branch #{$id}",
            'cash' => round((float) ($cash[$id] ?? 0), 2),
            'pending_count' => (int) ($pending[$id]->c ?? 0),
            'pending_amount' => round((float) ($pending[$id]->a ?? 0), 2),
        ])->values()->all();
    }
}
