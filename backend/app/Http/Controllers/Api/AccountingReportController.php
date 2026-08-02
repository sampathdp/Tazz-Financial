<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalLine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Trial Balance, General Ledger, Profit & Loss and Balance Sheet — all
 * read-only views over journal_lines (see the Chart of Accounts guide,
 * Section 6 Schedules 6/7/8/10). None of them are stored; every figure is
 * computed live from ChartOfAccount::balancesAsOf() so they can never
 * disagree with each other or with the ledger itself.
 */
class AccountingReportController extends Controller
{
    private const TYPES = ['trial-balance', 'general-ledger', 'profit-loss', 'balance-sheet'];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'trial-balance' => $this->trialBalance($request),
            'general-ledger' => $this->generalLedger($request),
            'profit-loss' => $this->profitAndLoss($request),
            'balance-sheet' => $this->balanceSheet($request),
        };
    }

    private function trialBalance(Request $request)
    {
        $asOf = $request->query('as_of');
        $balances = ChartOfAccount::balancesAsOf($asOf);

        return ChartOfAccount::orderBy('code')->get()->map(function (ChartOfAccount $account) use ($balances) {
            $b = $balances->get($account->id);

            return [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'total_debit' => $b->total_debit ?? '0.00',
                'total_credit' => $b->total_credit ?? '0.00',
                'balance' => $b->balance ?? '0.00',
            ];
        })->values();
    }

    private function generalLedger(Request $request)
    {
        $accountId = $request->query('account_id');
        if (!$accountId) {
            throw ValidationException::withMessages(['account_id' => 'account_id is required for this report.']);
        }

        $lines = JournalLine::with('entry:id,code,entry_date,description,reference_type,reference_id,status')
            ->where('account_id', $accountId)
            ->whereHas('entry', function ($q) use ($request) {
                $q->where('status', 'Posted')
                    ->when($request->query('from'), fn ($qq, $d) => $qq->whereDate('entry_date', '>=', $d))
                    ->when($request->query('to'), fn ($qq, $d) => $qq->whereDate('entry_date', '<=', $d));
            })
            ->get()
            ->sort(function (JournalLine $a, JournalLine $b) {
                $byDate = $a->entry->entry_date <=> $b->entry->entry_date;

                return $byDate !== 0 ? $byDate : ($a->id <=> $b->id);
            })
            ->values();

        $account = ChartOfAccount::findOrFail($accountId);
        $running = '0.00';
        $rows = $lines->map(function (JournalLine $line) use (&$running, $account) {
            $delta = $account->normal_balance === 'Debit'
                ? bcsub((string) $line->debit, (string) $line->credit, 2)
                : bcsub((string) $line->credit, (string) $line->debit, 2);
            $running = bcadd($running, $delta, 2);

            return [
                'journal_entry_code' => $line->entry->code,
                'entry_date' => $line->entry->entry_date->toDateString(),
                'description' => $line->description ?? $line->entry->description,
                'reference_type' => $line->entry->reference_type,
                'reference_id' => $line->entry->reference_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'running_balance' => $running,
            ];
        });

        return ['account' => $account->only(['id', 'code', 'name', 'type', 'normal_balance']), 'lines' => $rows];
    }

    private function profitAndLoss(Request $request)
    {
        $from = $request->query('from');
        $to = $request->query('to');
        [$income, $expense, $totalIncome, $totalExpense] = $this->plFigures($from, $to);

        return [
            'from' => $from, 'to' => $to,
            'income' => $income, 'expense' => $expense,
            'total_income' => $totalIncome, 'total_expense' => $totalExpense,
            'net_profit' => bcsub($totalIncome, $totalExpense, 2),
        ];
    }

    private function balanceSheet(Request $request)
    {
        $asOf = $request->query('as_of') ?? now()->toDateString();
        $balances = ChartOfAccount::balancesAsOf($asOf);
        $accounts = ChartOfAccount::whereIn('type', ['Asset', 'Liability', 'Equity'])->orderBy('code')->get();

        $rows = ['Asset' => [], 'Liability' => [], 'Equity' => []];
        $totals = ['Asset' => '0.00', 'Liability' => '0.00', 'Equity' => '0.00'];
        foreach ($accounts as $account) {
            // 3030 Current Year Profit/Loss is never posted to directly — it's
            // this period's net profit, computed live below instead of read
            // off journal_lines (which would always show 0.00 for it).
            if ($account->code === '3030') {
                continue;
            }
            $balance = $balances->get($account->id)->balance ?? '0.00';
            $rows[$account->type][] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'balance' => $balance];
            $totals[$account->type] = bcadd($totals[$account->type], $balance, 2);
        }

        $yearStart = Carbon::parse($asOf)->startOfYear()->toDateString();
        [, , $totalIncome, $totalExpense] = $this->plFigures($yearStart, $asOf);
        $currentYearProfit = bcsub($totalIncome, $totalExpense, 2);
        $threeThirty = ChartOfAccount::system('3030');
        $rows['Equity'][] = ['account_id' => $threeThirty->id, 'code' => $threeThirty->code, 'name' => $threeThirty->name, 'balance' => $currentYearProfit];
        $totals['Equity'] = bcadd($totals['Equity'], $currentYearProfit, 2);

        $totalLiabAndEquity = bcadd($totals['Liability'], $totals['Equity'], 2);

        return [
            'as_of' => $asOf,
            'assets' => $rows['Asset'], 'liabilities' => $rows['Liability'], 'equity' => $rows['Equity'],
            'total_assets' => $totals['Asset'],
            'total_liabilities' => $totals['Liability'],
            'total_equity' => $totals['Equity'],
            'total_liabilities_and_equity' => $totalLiabAndEquity,
            // Structurally guaranteed by LedgerPoster's balance check on every entry —
            // false here would mean the chart of accounts itself is misconfigured
            // (e.g. an account with the wrong normal_balance), not a data error.
            'balanced' => bccomp($totals['Asset'], $totalLiabAndEquity, 2) === 0,
        ];
    }

    /** Income/Expense account balances for a period, plus their totals — shared by P&L and Balance Sheet's 3030 figure so both can never disagree. */
    private function plFigures(?string $from, ?string $to): array
    {
        $balances = ChartOfAccount::balancesAsOf($to, $from);
        $accounts = ChartOfAccount::whereIn('type', ['Income', 'Expense'])->orderBy('code')->get();

        $rows = ['Income' => [], 'Expense' => []];
        $totalIncome = '0.00';
        $totalExpense = '0.00';
        foreach ($accounts as $account) {
            $balance = $balances->get($account->id)->balance ?? '0.00';
            $rows[$account->type][] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'balance' => $balance];
            if ($account->type === 'Income') {
                $totalIncome = bcadd($totalIncome, $balance, 2);
            } else {
                $totalExpense = bcadd($totalExpense, $balance, 2);
            }
        }

        return [$rows['Income'], $rows['Expense'], $totalIncome, $totalExpense];
    }
}
