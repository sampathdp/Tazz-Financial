<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalLine;
use App\Support\AccrualInterestCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Trial Balance, General Ledger, Profit & Loss, Balance Sheet and Ledger
 * Drift — all read-only views over journal_lines (see the Chart of Accounts
 * guide, Section 6 Schedules 6/7/8/10). None of them are stored; every figure
 * is computed live from ChartOfAccount::balancesAsOf() so they can never
 * disagree with each other or with the ledger itself.
 */
class AccountingReportController extends Controller
{
    private const TYPES = ['trial-balance', 'general-ledger', 'profit-loss', 'balance-sheet', 'ledger-drift'];

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
            'ledger-drift' => $this->ledgerDrift($request),
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
                'account_type' => $account->account_type,
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

        $lines = JournalLine::with('entry:id,code,entry_date,description,source_type,source_id,status')
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
                'source_type' => $line->entry->source_type,
                'source_id' => $line->entry->source_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'running_balance' => $running,
            ];
        });

        return ['account' => $account->only(['id', 'code', 'name', 'account_type', 'normal_balance']), 'lines' => $rows];
    }

    private function profitAndLoss(Request $request)
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $basis = $request->query('basis') === 'accrual' ? 'accrual' : 'cash';
        [$income, $expense, $totalIncome, $totalExpense] = $this->plFigures($from, $to, $basis);

        return [
            'from' => $from, 'to' => $to, 'basis' => $basis,
            'income' => $income, 'expense' => $expense,
            'total_income' => $totalIncome, 'total_expense' => $totalExpense,
            'net_profit' => bcsub($totalIncome, $totalExpense, 2),
        ];
    }

    private function balanceSheet(Request $request)
    {
        $asOf = $request->query('as_of') ?? now()->toDateString();
        $balances = ChartOfAccount::balancesAsOf($asOf);
        $accounts = ChartOfAccount::whereIn('account_type', ['Asset', 'Liability', 'Equity'])->orderBy('code')->get();

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
            $rows[$account->account_type][] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'balance' => $balance];
            $totals[$account->account_type] = bcadd($totals[$account->account_type], $balance, 2);
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

    /**
     * cash_accounts.current_balance / bank_accounts.current_balance are a
     * separate, independently-maintained balance for the same till/bank
     * account that chart_of_accounts/journal_lines also tracks via each
     * account's own chart_of_account_id (this dual-ledger shape is
     * deliberate and transitional, not a bug — see that column's migration).
     * But nothing enforces the two agree, so this surfaces any drift instead
     * of assuming it can't happen: for every till/bank account with a linked
     * GL leaf, the "book" balance (current_balance) against the GL's own
     * derived balance for that leaf account. A nonzero variance means a
     * posting updated one side without the other — investigate before
     * trusting either figure for that account.
     */
    private function ledgerDrift(Request $request)
    {
        $asOf = $request->query('as_of');
        $balances = ChartOfAccount::balancesAsOf($asOf);

        $tillRows = CashAccount::whereNotNull('chart_of_account_id')->with('chartOfAccount')->get()
            ->map(fn (CashAccount $till) => $this->driftRow('CashAccount', $till, $till->chartOfAccount, $balances));

        $bankRows = BankAccount::whereNotNull('chart_of_account_id')->with('chartOfAccount')->get()
            ->map(fn (BankAccount $bank) => $this->driftRow('BankAccount', $bank, $bank->chartOfAccount, $balances));

        $rows = $tillRows->concat($bankRows)
            ->sortByDesc(fn ($r) => abs((float) $r['variance']))
            ->values();

        return [
            'as_of' => $asOf ?? now()->toDateString(),
            'rows' => $rows,
            'has_drift' => $rows->contains(fn ($r) => bccomp($r['variance'], '0.00', 2) !== 0),
        ];
    }

    private function driftRow(string $kind, $ledgerAccount, ChartOfAccount $glAccount, \Illuminate\Support\Collection $balances): array
    {
        $book = (string) $ledgerAccount->current_balance;
        $gl = $balances->get($glAccount->id)->balance ?? '0.00';

        return [
            'kind' => $kind,
            'id' => $ledgerAccount->id,
            'name' => $ledgerAccount->name,
            'gl_account_id' => $glAccount->id,
            'gl_account_code' => $glAccount->code,
            'book_balance' => $book,
            'gl_balance' => $gl,
            'variance' => bcsub($book, $gl, 2),
        ];
    }

    /** Accrual Basis only ever touches these two — see AccrualInterestCalculator's docblock for why interest income is the one figure with an equivalent "supposed to earn" computation; other income/expense have no such schedule to compute from. */
    private const ACCRUAL_INCOME_ACCOUNTS = ['4010' => 'Loan', '4015' => 'SpeedDraft'];

    /**
     * Income/Expense account balances for a period, plus their totals — shared
     * by P&L and Balance Sheet's 3030 figure so both can never disagree (the
     * Balance Sheet always calls this with the default 'cash' basis, since its
     * asset/liability figures are ledger-based and its "balanced" invariant
     * only holds against a ledger-based profit figure).
     */
    private function plFigures(?string $from, ?string $to, string $basis = 'cash'): array
    {
        $balances = ChartOfAccount::balancesAsOf($to, $from);
        $accounts = ChartOfAccount::whereIn('account_type', ['Income', 'Expense'])->orderBy('code')->get();

        $rows = ['Income' => [], 'Expense' => []];
        $totalIncome = '0.00';
        $totalExpense = '0.00';
        foreach ($accounts as $account) {
            $balance = $balances->get($account->id)->balance ?? '0.00';
            if ($basis === 'accrual' && isset(self::ACCRUAL_INCOME_ACCOUNTS[$account->code])) {
                $balance = AccrualInterestCalculator::incomeForPeriod(self::ACCRUAL_INCOME_ACCOUNTS[$account->code], $from, $to);
            }
            $rows[$account->account_type][] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'balance' => $balance];
            if ($account->account_type === 'Income') {
                $totalIncome = bcadd($totalIncome, $balance, 2);
            } else {
                $totalExpense = bcadd($totalExpense, $balance, 2);
            }
        }

        return [$rows['Income'], $rows['Expense'], $totalIncome, $totalExpense];
    }
}
