<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\CashFloatSettlement;
use App\Models\CashReconciliation;
use App\Models\CashSession;
use App\Models\ChartOfAccount;
use App\Models\FinanceAuditEvent;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Support\CurrentBranch;
use App\Support\ReportExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Finance & cash reports (Finance > Reports). Same type-dispatch shape as the
 * other *ReportController classes. Everything reads off the Finance module's
 * own tables — finance_transactions (the immutable ledger), finance_requests
 * (the intent queue), cash_sessions / cash_float_settlements /
 * cash_reconciliations (the count-and-true records) and finance_audit_events.
 *
 * All figures are branch-scoped through CurrentBranch, so a branch user only
 * ever sees their own tills.
 */
class FinanceReportController extends Controller
{
    private const TYPES = [
        'daily-cash', 'cash-position', 'cash-flow', 'transfers', 'floats',
        'requests-pending', 'requests-unpaid', 'reversals', 'variance', 'audit',
    ];

    public function show(Request $request, string $type)
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        return $this->build($request, $type);
    }

    public function exportPdf(Request $request, string $type)
    {
        abort_unless(in_array($type, self::TYPES, true), 404);

        $data = $this->build($request, $type);
        [$title, $sections, $meta] = $this->pdf($type, $data);

        return ReportExport::pdf($title, $sections, null, $meta)->download(ReportExport::filename('finance-' . $type));
    }

    private function build(Request $request, string $type): array
    {
        return match ($type) {
            'daily-cash' => $this->dailyCash($request),
            'cash-position' => $this->cashPosition(),
            'cash-flow' => $this->cashFlow($request),
            'transfers' => $this->transfers($request),
            'floats' => $this->floats($request),
            'requests-pending' => $this->requestsPending(),
            'requests-unpaid' => $this->requestsUnpaid(),
            'reversals' => $this->reversals($request),
            'variance' => $this->variance($request),
            'audit' => $this->audit($request),
        };
    }

    /* ------------------------------------------------------------------ *
     *  Reports
     * ------------------------------------------------------------------ */

    /**
     * Opening / inflow / outflow / closing for every active cash till on one
     * business day (or a range). Opening & closing are walked back from the
     * live cash_accounts.current_balance using the ledger movements, so the
     * report always ties out to the cache figure shown everywhere else.
     */
    private function dailyCash(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : now()->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : $from;

        $accounts = CurrentBranch::applyScope(CashAccount::with('branch:id,name'))
            ->orderBy('name')->get(['id', 'branch_id', 'name', 'account_type', 'current_balance', 'status']);

        $inRange = $this->cashLegSums($from, $to);
        $afterRange = $this->cashLegSums(Carbon::parse($to)->addDay()->toDateString(), null);

        $rows = $accounts->map(function (CashAccount $a) use ($inRange, $afterRange) {
            $ir = $inRange[$a->id] ?? ['in' => 0.0, 'out' => 0.0, 'count' => 0];
            $ar = $afterRange[$a->id] ?? ['in' => 0.0, 'out' => 0.0, 'count' => 0];
            $closing = round((float) $a->current_balance - ($ar['in'] - $ar['out']), 2);
            $opening = round($closing - ($ir['in'] - $ir['out']), 2);

            return [
                'cashAccountId' => $a->id,
                'account' => $a->name,
                'accountType' => $a->account_type,
                'branch' => $a->branch?->name ?? '—',
                'opening' => $opening,
                'inflow' => round($ir['in'], 2),
                'outflow' => round($ir['out'], 2),
                'net' => round($ir['in'] - $ir['out'], 2),
                'closing' => $closing,
                'txnCount' => $ir['count'],
            ];
        })->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'opening' => round($rows->sum('opening'), 2),
                'inflow' => round($rows->sum('inflow'), 2),
                'outflow' => round($rows->sum('outflow'), 2),
                'net' => round($rows->sum('net'), 2),
                'closing' => round($rows->sum('closing'), 2),
                'txnCount' => (int) $rows->sum('txnCount'),
            ],
        ];
    }

    /**
     * Every cash account's live cache balance next to its ledger (GL) balance,
     * with the drift between them — the "is the cache still trustworthy" view.
     */
    private function cashPosition(): array
    {
        $accounts = CurrentBranch::applyScope(CashAccount::with('branch:id,name', 'responsibleUser:id,name'))
            ->orderBy('account_type')->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'code', 'account_type', 'current_balance', 'status', 'responsible_user_id', 'chart_of_account_id', 'last_reconciled_at']);

        $glBalances = ChartOfAccount::balancesAsOf()->keyBy('account_id');

        $rows = $accounts->map(function (CashAccount $a) use ($glBalances) {
            $gl = $a->chart_of_account_id && $glBalances->has($a->chart_of_account_id)
                ? round((float) $glBalances[$a->chart_of_account_id]->balance, 2)
                : null;
            $cache = round((float) $a->current_balance, 2);

            return [
                'cashAccountId' => $a->id,
                'account' => $a->name,
                'code' => $a->code,
                'accountType' => $a->account_type,
                'branch' => $a->branch?->name ?? '—',
                'custodian' => $a->responsibleUser?->name ?? '—',
                'cacheBalance' => $cache,
                'glBalance' => $gl,
                'drift' => $gl === null ? null : round($cache - $gl, 2),
                'status' => $a->status,
                'lastReconciledAt' => optional($a->last_reconciled_at)->toIso8601String(),
            ];
        })->values();

        $byType = $rows->groupBy('accountType')->map(fn ($g) => round($g->sum('cacheBalance'), 2));

        return [
            'asOf' => now()->toIso8601String(),
            'rows' => $rows,
            'byType' => $byType,
            'totalCache' => round($rows->where('status', 'Active')->sum('cacheBalance'), 2),
            'totalDrift' => round($rows->sum(fn ($r) => $r['drift'] ?? 0), 2),
        ];
    }

    /** finance_transactions grouped by type over a period, with the net cash impact of each. */
    private function cashFlow(Request $request): array
    {
        [$from, $to] = $this->period($request);

        $txns = CurrentBranch::applyScope(
            FinanceTransaction::query()
                ->whereBetween(DB::raw('DATE(executed_at)'), [$from, $to])
        )->get(['id', 'txn_type', 'direction', 'amount', 'source_account_type', 'dest_account_type']);

        $byType = $txns->groupBy('txn_type')->map(function ($g) {
            $cashIn = round((float) $g->where('dest_account_type', 'cash')->sum('amount'), 2);
            $cashOut = round((float) $g->where('source_account_type', 'cash')->sum('amount'), 2);

            return [
                'count' => $g->count(),
                'amount' => round((float) $g->sum('amount'), 2),
                'cashIn' => $cashIn,
                'cashOut' => $cashOut,
                'netCash' => round($cashIn - $cashOut, 2),
            ];
        });

        return [
            'from' => $from,
            'to' => $to,
            'byType' => $byType,
            'totalIn' => round($byType->sum('cashIn'), 2),
            'totalOut' => round($byType->sum('cashOut'), 2),
            'netCash' => round($byType->sum('netCash'), 2),
            'txnCount' => $txns->count(),
        ];
    }

    /** Every transfer / float movement (TRANSFER, FLOAT_ISSUE, FLOAT_RETURN) over a period. */
    private function transfers(Request $request): array
    {
        [$from, $to] = $this->period($request);

        $txns = CurrentBranch::applyScope(
            FinanceTransaction::with('executor:id,name', 'branch:id,name')
                ->whereIn('txn_type', ['TRANSFER', 'FLOAT_ISSUE', 'FLOAT_RETURN'])
                ->whereBetween(DB::raw('DATE(executed_at)'), [$from, $to])
        )->orderByDesc('executed_at')->get();

        $names = $this->accountNameMap($txns);

        $rows = $txns->map(fn (FinanceTransaction $t) => [
            'id' => $t->id,
            'code' => $t->code,
            'date' => optional($t->executed_at)->toIso8601String(),
            'txnType' => $t->txn_type,
            'from' => $names[$this->key($t->source_account_type, $t->source_account_id)] ?? '—',
            'to' => $names[$this->key($t->dest_account_type, $t->dest_account_id)] ?? '—',
            'amount' => round((float) $t->amount, 2),
            'status' => $t->status,
            'branch' => $t->branch?->name ?? '—',
            'by' => $t->executor?->name ?? '—',
        ])->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'byType' => $txns->groupBy('txn_type')->map(fn ($g) => round((float) $g->sum('amount'), 2)),
            'total' => round($rows->sum('amount'), 2),
            'count' => $rows->count(),
        ];
    }

    /** Open floats right now, plus every float settlement over a period. */
    private function floats(Request $request): array
    {
        [$from, $to] = $this->period($request);

        $open = CurrentBranch::applyScope(
            CashAccount::with('branch:id,name', 'responsibleUser:id,name', 'parent:id,name')
                ->where('account_type', 'CashierFloat')
                ->where('status', 'Active')
        )->orderBy('name')->get(['id', 'branch_id', 'name', 'current_balance', 'responsible_user_id', 'parent_cash_account_id', 'status', 'last_reconciled_at']);

        $settlements = CashFloatSettlement::with('floatAccount:id,name', 'homeAccount:id,name,branch_id', 'homeAccount.branch:id,name', 'custodian:id,name', 'settler:id,name')
            ->whereBetween(DB::raw('DATE(opened_at)'), [$from, $to])
            ->whereHas('homeAccount', fn ($q) => CurrentBranch::applyScope($q))
            ->orderByDesc('opened_at')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'openFloats' => $open->map(fn (CashAccount $a) => [
                'cashAccountId' => $a->id,
                'float' => $a->name,
                'branch' => $a->branch?->name ?? '—',
                'custodian' => $a->responsibleUser?->name ?? '—',
                'home' => $a->parent?->name ?? '—',
                'balance' => round((float) $a->current_balance, 2),
                'lastReconciledAt' => optional($a->last_reconciled_at)->toIso8601String(),
            ])->values(),
            'openTotal' => round((float) $open->sum('current_balance'), 2),
            'settlements' => $settlements->map(fn (CashFloatSettlement $s) => [
                'id' => $s->id,
                'float' => $s->floatAccount?->name ?? '—',
                'branch' => $s->homeAccount?->branch?->name ?? '—',
                'custodian' => $s->custodian?->name ?? '—',
                'issued' => round((float) $s->issued_total, 2),
                'expected' => round((float) $s->expected_amount, 2),
                'counted' => round((float) $s->counted_amount, 2),
                'shortage' => round((float) $s->shortage, 2),
                'excess' => round((float) $s->excess, 2),
                'returned' => round((float) $s->returned_total, 2),
                'status' => $s->status,
                'openedAt' => optional($s->opened_at)->toIso8601String(),
                'closedAt' => optional($s->closed_at)->toIso8601String(),
                'settledBy' => $s->settler?->name ?? '—',
            ])->values(),
            'settlementShortage' => round((float) $settlements->sum('shortage'), 2),
            'settlementExcess' => round((float) $settlements->sum('excess'), 2),
        ];
    }

    /** Requests still awaiting a decision (Draft / Submitted / PendingApproval). */
    private function requestsPending(): array
    {
        $rows = CurrentBranch::applyScope(
            FinanceRequest::with('requester:id,name', 'branch:id,name')
                ->whereIn('status', ['Draft', 'Submitted', 'PendingApproval'])
        )->orderBy('requested_at')->get();

        return [
            'rows' => $this->requestRows($rows, 'requested_at'),
            'total' => round((float) $rows->sum('amount'), 2),
            'count' => $rows->count(),
        ];
    }

    /** Requests approved but the money has not left yet (Approved / ReadyForPayment / Processing / InTransit). */
    private function requestsUnpaid(): array
    {
        $rows = CurrentBranch::applyScope(
            FinanceRequest::with('requester:id,name', 'approver:id,name', 'branch:id,name')
                ->whereIn('status', ['Approved', 'ReadyForPayment', 'Processing', 'InTransit'])
        )->orderBy('approved_at')->get();

        return [
            'rows' => $this->requestRows($rows, 'approved_at'),
            'total' => round((float) $rows->sum('amount'), 2),
            'count' => $rows->count(),
        ];
    }

    /** Every reversed transaction and its reversing entry over a period. */
    private function reversals(Request $request): array
    {
        [$from, $to] = $this->period($request);

        $txns = CurrentBranch::applyScope(
            FinanceTransaction::with('executor:id,name', 'reversal:id,code,executed_at', 'branch:id,name')
                ->where('txn_type', '!=', 'REVERSAL')
                ->where('status', 'Reversed')
                ->whereBetween(DB::raw('DATE(executed_at)'), [$from, $to])
        )->orderByDesc('executed_at')->get();

        // The reason lives on the transaction.reversed audit event.
        $reasons = FinanceAuditEvent::where('action', 'transaction.reversed')
            ->where('subject_type', 'FinanceTransaction')
            ->whereIn('subject_id', $txns->pluck('id'))
            ->pluck('reason', 'subject_id');

        $rows = $txns->map(fn (FinanceTransaction $t) => [
            'id' => $t->id,
            'code' => $t->code,
            'txnType' => $t->txn_type,
            'amount' => round((float) $t->amount, 2),
            'originalDate' => optional($t->executed_at)->toIso8601String(),
            'reversalCode' => $t->reversal?->code ?? '—',
            'reversalDate' => optional($t->reversal?->executed_at)->toIso8601String(),
            'reason' => $reasons[$t->id] ?? '—',
            'branch' => $t->branch?->name ?? '—',
            'by' => $t->executor?->name ?? '—',
        ])->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total' => round($rows->sum('amount'), 2),
            'count' => $rows->count(),
        ];
    }

    /**
     * Every counted-vs-expected difference over a period, from all three count
     * records: cash sessions, float settlements and daily reconciliations.
     */
    private function variance(Request $request): array
    {
        [$from, $to] = $this->period($request);

        $sessions = CurrentBranch::applyScope(
            CashSession::with('cashAccount:id,name', 'branch:id,name')
                ->whereIn('status', ['Closed', 'Verified'])
                ->whereBetween('business_date', [$from, $to])
        )->get();

        $settlements = CashFloatSettlement::with('floatAccount:id,name', 'homeAccount:id,name,branch_id', 'homeAccount.branch:id,name')
            ->where('status', 'Settled')
            ->whereBetween(DB::raw('DATE(closed_at)'), [$from, $to])
            ->whereHas('homeAccount', fn ($q) => CurrentBranch::applyScope($q))
            ->get();

        $recons = CurrentBranch::applyScope(
            CashReconciliation::with('branch:id,name')
                ->whereBetween('as_at_date', [$from, $to])
        )->get();

        $rows = collect();
        foreach ($sessions as $s) {
            $rows->push([
                'source' => 'Cash Session',
                'ref' => $s->cashAccount?->name ?? ('Session #' . $s->id),
                'branch' => $s->branch?->name ?? '—',
                'date' => optional($s->business_date)->toDateString(),
                'expected' => round((float) $s->expected_closing, 2),
                'counted' => round((float) $s->counted_closing, 2),
                'shortage' => round((float) $s->shortage, 2),
                'excess' => round((float) $s->excess, 2),
                'status' => $s->status,
            ]);
        }
        foreach ($settlements as $s) {
            $rows->push([
                'source' => 'Float Settlement',
                'ref' => $s->floatAccount?->name ?? ('Settlement #' . $s->id),
                'branch' => $s->homeAccount?->branch?->name ?? '—',
                'date' => optional($s->closed_at)->toDateString(),
                'expected' => round((float) $s->expected_amount, 2),
                'counted' => round((float) $s->counted_amount, 2),
                'shortage' => round((float) $s->shortage, 2),
                'excess' => round((float) $s->excess, 2),
                'status' => $s->status,
            ]);
        }
        foreach ($recons as $r) {
            $variance = (float) $r->variance;
            $rows->push([
                'source' => 'Daily Reconciliation',
                'ref' => 'Recon ' . optional($r->as_at_date)->toDateString(),
                'branch' => $r->branch?->name ?? '—',
                'date' => optional($r->as_at_date)->toDateString(),
                'expected' => round((float) $r->total_cash, 2),
                'counted' => round((float) $r->counted_total, 2),
                'shortage' => $variance < 0 ? round(abs($variance), 2) : 0.0,
                'excess' => $variance > 0 ? round($variance, 2) : 0.0,
                'status' => $r->status ?? 'Draft',
            ]);
        }

        $rows = $rows->sortByDesc('date')->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totalShortage' => round($rows->sum('shortage'), 2),
            'totalExcess' => round($rows->sum('excess'), 2),
            'net' => round($rows->sum('excess') - $rows->sum('shortage'), 2),
            'count' => $rows->count(),
        ];
    }

    /** Audit-trail summary over a period: activity by action and by actor, plus the recent tail. */
    private function audit(Request $request): array
    {
        [$from, $to] = $this->period($request);

        $events = CurrentBranch::applyScope(
            FinanceAuditEvent::with('actor:id,name')
                ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
        )->orderByDesc('id')->get();

        return [
            'from' => $from,
            'to' => $to,
            'byAction' => $events->groupBy('action')->map->count()->sortDesc(),
            'byActor' => $events->groupBy(fn ($e) => $e->actor?->name ?? 'System')->map->count()->sortDesc(),
            'recent' => $events->take(100)->map(fn (FinanceAuditEvent $e) => [
                'id' => $e->id,
                'when' => optional($e->created_at)->toIso8601String(),
                'actor' => $e->actor?->name ?? 'System',
                'action' => $e->action,
                'subject' => $e->subject_type ? $e->subject_type . ' #' . $e->subject_id : '—',
                'amount' => $e->amount !== null ? round((float) $e->amount, 2) : null,
                'status' => trim(($e->from_status ?? '') . ' → ' . ($e->to_status ?? ''), ' →'),
                'reason' => $e->reason ?? '—',
            ])->values(),
            'count' => $events->count(),
        ];
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /** @return array{0: string, 1: string} [from, to] as Y-m-d, defaulting to the current month. */
    private function period(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : now()->startOfMonth()->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : now()->toDateString();

        return [$from, $to];
    }

    /**
     * Per cash-account inflow / outflow / txn-count from finance_transactions
     * over a date window (either bound nullable). A transaction's source cash
     * leg is an outflow, its dest cash leg an inflow.
     *
     * @return array<int, array{in: float, out: float, count: int}>
     */
    private function cashLegSums(?string $from, ?string $to): array
    {
        $apply = function ($q) use ($from, $to) {
            if ($from) {
                $q->whereDate('executed_at', '>=', $from);
            }
            if ($to) {
                $q->whereDate('executed_at', '<=', $to);
            }

            return CurrentBranch::applyScope($q);
        };

        $out = [];

        $outflows = $apply(FinanceTransaction::query())->where('source_account_type', 'cash')
            ->groupBy('source_account_id')
            ->get([
                'source_account_id as id',
                DB::raw('SUM(amount) as amt'),
                DB::raw('COUNT(*) as cnt'),
            ]);
        foreach ($outflows as $r) {
            $out[(int) $r->id] = ['in' => 0.0, 'out' => (float) $r->amt, 'count' => (int) $r->cnt];
        }

        $inflows = $apply(FinanceTransaction::query())->where('dest_account_type', 'cash')
            ->groupBy('dest_account_id')
            ->get([
                'dest_account_id as id',
                DB::raw('SUM(amount) as amt'),
                DB::raw('COUNT(*) as cnt'),
            ]);
        foreach ($inflows as $r) {
            $id = (int) $r->id;
            $out[$id] ??= ['in' => 0.0, 'out' => 0.0, 'count' => 0];
            $out[$id]['in'] = (float) $r->amt;
            $out[$id]['count'] += (int) $r->cnt;
        }

        return $out;
    }

    /** Shared row shape for the two request reports. */
    private function requestRows($rows, string $dateField)
    {
        return $rows->map(fn (FinanceRequest $r) => [
            'id' => $r->id,
            'code' => $r->code,
            'requestType' => $r->request_type,
            'amount' => round((float) $r->amount, 2),
            'payee' => $r->payee_name ?: '—',
            'purpose' => $r->purpose ?: '—',
            'status' => $r->status,
            'branch' => $r->branch?->name ?? '—',
            'requestedBy' => $r->requester?->name ?? '—',
            'approvedBy' => $r->approver?->name ?? '—',
            'date' => optional($r->{$dateField})->toIso8601String(),
            'ageDays' => $r->{$dateField} ? Carbon::parse($r->{$dateField})->diffInDays(now()) : null,
        ])->values();
    }

    /** Batch-resolve cash & bank account names for a set of transactions. */
    private function accountNameMap($txns): array
    {
        $cashIds = collect();
        $bankIds = collect();
        foreach ($txns as $t) {
            foreach ([[$t->source_account_type, $t->source_account_id], [$t->dest_account_type, $t->dest_account_id]] as [$type, $id]) {
                if (!$id) {
                    continue;
                }
                (strtolower((string) $type) === 'bank' ? $bankIds : $cashIds)->push((int) $id);
            }
        }

        $map = [];
        foreach (CashAccount::whereIn('id', $cashIds->unique())->get(['id', 'name']) as $a) {
            $map[$this->key('cash', $a->id)] = $a->name;
        }
        foreach (BankAccount::whereIn('id', $bankIds->unique())->get(['id', 'name']) as $a) {
            $map[$this->key('bank', $a->id)] = $a->name;
        }

        return $map;
    }

    private function key(?string $type, $id): string
    {
        return strtolower((string) $type) . ':' . (int) $id;
    }

    /* ------------------------------------------------------------------ *
     *  PDF section builders — one generic table per report
     * ------------------------------------------------------------------ */

    private function pdf(string $type, array $d): array
    {
        $money = fn (string $k) => ['key' => $k, 'label' => ucfirst($k), 'align' => 'right', 'format' => 'money'];

        return match ($type) {
            'daily-cash' => ['Daily Cash Report', [[
                'columns' => [
                    ['key' => 'account', 'label' => 'Cash Account'], ['key' => 'branch', 'label' => 'Branch'],
                    ['key' => 'opening', 'label' => 'Opening', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'inflow', 'label' => 'Inflow', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'outflow', 'label' => 'Outflow', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'closing', 'label' => 'Closing', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'txnCount', 'label' => 'Txns', 'align' => 'right'],
                ],
                'rows' => $d['rows'],
                'totalRow' => ['account' => 'Total', 'opening' => $d['totals']['opening'], 'inflow' => $d['totals']['inflow'], 'outflow' => $d['totals']['outflow'], 'closing' => $d['totals']['closing'], 'txnCount' => $d['totals']['txnCount']],
                'emptyText' => 'No cash accounts',
            ]], "Period: {$d['from']} to {$d['to']}"],

            'cash-position' => ['Cash Position', [[
                'columns' => [
                    ['key' => 'account', 'label' => 'Cash Account'], ['key' => 'accountType', 'label' => 'Type'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'custodian', 'label' => 'Custodian'],
                    ['key' => 'cacheBalance', 'label' => 'Cache', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'glBalance', 'label' => 'Ledger', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'drift', 'label' => 'Drift', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
                ],
                'rows' => $d['rows'],
                'totalRow' => ['account' => 'Total (Active)', 'cacheBalance' => $d['totalCache'], 'drift' => $d['totalDrift']],
                'emptyText' => 'No cash accounts',
            ]], null],

            'cash-flow' => ['Cash Flow by Type', [[
                'columns' => [
                    ['key' => 'type', 'label' => 'Transaction Type'], ['key' => 'count', 'label' => 'Count', 'align' => 'right'],
                    ['key' => 'cashIn', 'label' => 'Cash In', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'cashOut', 'label' => 'Cash Out', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'netCash', 'label' => 'Net Cash', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => collect($d['byType'])->map(fn ($v, $k) => ['type' => $k] + $v)->values()->all(),
                'totalRow' => ['type' => 'Total', 'cashIn' => $d['totalIn'], 'cashOut' => $d['totalOut'], 'netCash' => $d['netCash']],
                'emptyText' => 'No transactions in this range',
            ]], "Period: {$d['from']} to {$d['to']}"],

            'transfers' => ['Transfer & Float Movement', [[
                'columns' => [
                    ['key' => 'code', 'label' => 'Code'], ['key' => 'txnType', 'label' => 'Type'], ['key' => 'from', 'label' => 'From'], ['key' => 'to', 'label' => 'To'],
                    ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'], ['key' => 'by', 'label' => 'By'],
                ],
                'rows' => collect($d['rows'])->map(fn ($r) => $r + ['from' => $r['from'], 'to' => $r['to']])->all(),
                'totalRow' => ['code' => 'Total', 'amount' => $d['total']],
                'emptyText' => 'No transfers in this range',
            ]], "Period: {$d['from']} to {$d['to']}"],

            'floats' => ['Cash Float Report', [
                [
                    'title' => 'Open Floats',
                    'columns' => [
                        ['key' => 'float', 'label' => 'Float'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'custodian', 'label' => 'Custodian'], ['key' => 'home', 'label' => 'Home Till'],
                        ['key' => 'balance', 'label' => 'Balance', 'align' => 'right', 'format' => 'money'],
                    ],
                    'rows' => $d['openFloats'],
                    'totalRow' => ['float' => 'Total', 'balance' => $d['openTotal']],
                    'emptyText' => 'No open floats',
                ],
                [
                    'title' => 'Settlements',
                    'columns' => [
                        ['key' => 'float', 'label' => 'Float'], ['key' => 'custodian', 'label' => 'Custodian'],
                        ['key' => 'issued', 'label' => 'Issued', 'align' => 'right', 'format' => 'money'],
                        ['key' => 'counted', 'label' => 'Counted', 'align' => 'right', 'format' => 'money'],
                        ['key' => 'shortage', 'label' => 'Shortage', 'align' => 'right', 'format' => 'money'],
                        ['key' => 'excess', 'label' => 'Excess', 'align' => 'right', 'format' => 'money'],
                        ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
                    ],
                    'rows' => $d['settlements'],
                    'totalRow' => ['float' => 'Total', 'shortage' => $d['settlementShortage'], 'excess' => $d['settlementExcess']],
                    'emptyText' => 'No settlements in this range',
                ],
            ], "Period: {$d['from']} to {$d['to']}"],

            'requests-pending' => ['Pending Finance Requests', [[
                'columns' => [
                    ['key' => 'code', 'label' => 'Code'], ['key' => 'requestType', 'label' => 'Type'], ['key' => 'payee', 'label' => 'Payee'],
                    ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
                    ['key' => 'requestedBy', 'label' => 'Requested By'], ['key' => 'ageDays', 'label' => 'Age (d)', 'align' => 'right'],
                ],
                'rows' => $d['rows'],
                'totalRow' => ['code' => 'Total', 'amount' => $d['total']],
                'emptyText' => 'Nothing pending',
            ]], null],

            'requests-unpaid' => ['Approved — Unpaid Requests', [[
                'columns' => [
                    ['key' => 'code', 'label' => 'Code'], ['key' => 'requestType', 'label' => 'Type'], ['key' => 'payee', 'label' => 'Payee'],
                    ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
                    ['key' => 'approvedBy', 'label' => 'Approved By'], ['key' => 'ageDays', 'label' => 'Age (d)', 'align' => 'right'],
                ],
                'rows' => $d['rows'],
                'totalRow' => ['code' => 'Total', 'amount' => $d['total']],
                'emptyText' => 'Nothing approved and unpaid',
            ]], null],

            'reversals' => ['Reversal Report', [[
                'columns' => [
                    ['key' => 'code', 'label' => 'Original'], ['key' => 'txnType', 'label' => 'Type'],
                    ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'reversalCode', 'label' => 'Reversal'], ['key' => 'reason', 'label' => 'Reason'], ['key' => 'by', 'label' => 'By'],
                ],
                'rows' => $d['rows'],
                'totalRow' => ['code' => 'Total', 'amount' => $d['total']],
                'emptyText' => 'No reversals in this range',
            ]], "Period: {$d['from']} to {$d['to']}"],

            'variance' => ['Cash Variance Report', [[
                'columns' => [
                    ['key' => 'source', 'label' => 'Source'], ['key' => 'ref', 'label' => 'Reference'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'date', 'label' => 'Date'],
                    ['key' => 'expected', 'label' => 'Expected', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'counted', 'label' => 'Counted', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'shortage', 'label' => 'Shortage', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'excess', 'label' => 'Excess', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => $d['rows'],
                'totalRow' => ['source' => 'Total', 'shortage' => $d['totalShortage'], 'excess' => $d['totalExcess']],
                'emptyText' => 'No variances in this range',
            ]], "Period: {$d['from']} to {$d['to']}"],

            'audit' => ['Transaction Audit', [
                [
                    'title' => 'By Action',
                    'columns' => [['key' => 'action', 'label' => 'Action'], ['key' => 'count', 'label' => 'Count', 'align' => 'right']],
                    'rows' => collect($d['byAction'])->map(fn ($v, $k) => ['action' => $k, 'count' => $v])->values()->all(),
                    'emptyText' => 'No activity in this range',
                ],
                [
                    'title' => 'Recent Events',
                    'columns' => [
                        ['key' => 'when', 'label' => 'When'], ['key' => 'actor', 'label' => 'Actor'], ['key' => 'action', 'label' => 'Action'],
                        ['key' => 'subject', 'label' => 'Subject'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'reason', 'label' => 'Reason'],
                    ],
                    'rows' => collect($d['recent'])->map(fn ($r) => array_merge($r, ['when' => substr((string) $r['when'], 0, 16)]))->all(),
                    'emptyText' => 'No activity in this range',
                ],
            ], "Period: {$d['from']} to {$d['to']}"],
        };
    }
}
