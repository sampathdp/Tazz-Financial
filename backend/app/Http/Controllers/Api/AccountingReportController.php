<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalLine;
use App\Support\AccrualInterestCalculator;
use App\Support\ReportExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    private const TYPES = ['trial-balance', 'general-ledger', 'profit-loss', 'balance-sheet', 'cash-flow', 'ledger-drift'];

    /** "Cash and cash equivalents" for the cash flow statement — till + bank, not undeposited cheques (1030), which aren't cash yet. */
    private const CASH_ACCOUNT_CODES = ['1010', '1020'];

    /** Everything else touching cash is Operating for a deposit-taking lender (loan disbursement/collection and customer deposits are the core business — see IAS 7 §15 treatment for financial institutions), so only these two get carved out. */
    private const INVESTING_ACCOUNT_CODES = ['1300'];
    private const FINANCING_ACCOUNT_CODES = ['3010'];

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
            'cash-flow' => $this->cashFlow($request),
            'ledger-drift' => $this->ledgerDrift($request),
        };
    }

    /**
     * A proper trial balance: one line per account, its *net closing balance*
     * placed in the Debit or Credit column by which side it lands on — not the
     * raw debit/credit turnover, which always foots equal by double-entry and
     * so proves nothing. The two balance columns footing equal is the actual
     * integrity check, surfaced as `totals.balanced`.
     *
     * `total_debit`/`total_credit` (period turnover) stay in each row for any
     * caller that still wants them, but the report itself is driven by
     * `debit_balance`/`credit_balance`. `is_abnormal` flags an account sitting
     * on the opposite side of its normal balance (e.g. a credit balance on an
     * asset) — usually a missing opening balance or an unposted counter-entry.
     */
    private function trialBalance(Request $request)
    {
        $asOf = $request->query('as_of') ?: now()->toDateString();
        $balances = ChartOfAccount::balancesAsOf($asOf);

        $rows = ChartOfAccount::orderBy('code')->get()->map(function (ChartOfAccount $account) use ($balances) {
            $b = $balances->get($account->id);
            $balance = $b->balance ?? '0.00'; // net, already oriented to the account's normal side
            $onNormalSide = bccomp($balance, '0.00', 2) >= 0;
            $magnitude = $onNormalSide ? $balance : bcsub('0.00', $balance, 2);
            $sideIsDebit = ($account->normal_balance === 'Debit') === $onNormalSide;

            return [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'normal_balance' => $account->normal_balance,
                'total_debit' => $b->total_debit ?? '0.00',
                'total_credit' => $b->total_credit ?? '0.00',
                'balance' => $balance,
                'debit_balance' => bccomp($magnitude, '0.00', 2) !== 0 && $sideIsDebit ? $magnitude : '0.00',
                'credit_balance' => bccomp($magnitude, '0.00', 2) !== 0 && !$sideIsDebit ? $magnitude : '0.00',
                'is_abnormal' => !$onNormalSide && bccomp($magnitude, '0.00', 2) !== 0,
            ];
        })->values();

        $totalDebit = $rows->reduce(fn ($c, $r) => bcadd($c, $r['debit_balance'], 2), '0.00');
        $totalCredit = $rows->reduce(fn ($c, $r) => bcadd($c, $r['credit_balance'], 2), '0.00');

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'difference' => bcsub($totalDebit, $totalCredit, 2),
                'balanced' => bccomp($totalDebit, $totalCredit, 2) === 0,
            ],
        ];
    }

    private function generalLedger(Request $request)
    {
        $accountId = $request->query('account_id');
        if (!$accountId) {
            throw ValidationException::withMessages(['account_id' => 'account_id is required for this report.']);
        }

        // Filters/sorts on journal_lines.entry_date directly — the denormalized
        // copy that (account_id, entry_date) is indexed on (see its migration) —
        // instead of a whereHas('entry', ...) join, and orders in SQL instead of
        // pulling every line into PHP just to sort it.
        //
        // Both Posted and Reversed entries are shown: a reversed original and
        // its reversal both belong in the account's ledger and net to zero
        // there (the `status` column on each row makes the correction visible).
        // Hiding the reversed original left the reversal looking like an
        // unexplained one-directional entry and broke the running balance.
        $lines = JournalLine::with('entry:id,code,entry_date,description,source_type,source_id,status')
            ->where('account_id', $accountId)
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('entry_date', '<=', $d))
            ->orderBy('entry_date')->orderBy('id')
            ->get();

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
        [$income, $expense, $totalIncomeCash, $totalIncomeAccrual, $totalExpense] = $this->plFiguresBothBases($from, $to);

        return [
            'from' => $from, 'to' => $to,
            'income' => $income, 'expense' => $expense,
            'total_income_cash' => $totalIncomeCash, 'total_income_accrual' => $totalIncomeAccrual,
            'total_expense' => $totalExpense,
            'net_profit_cash' => bcsub($totalIncomeCash, $totalExpense, 2),
            'net_profit_accrual' => bcsub($totalIncomeAccrual, $totalExpense, 2),
            'waterfall_cash' => $this->plWaterfall($from, $to, 'cash'),
            'waterfall_accrual' => $this->plWaterfall($from, $to, 'accrual'),
            'income_by_product' => $this->incomeByLoanProduct($from, $to),
        ];
    }

    /**
     * The full management P&L waterfall (Income → Interest Cost → Net Income
     * → Staff Cost/Opex → HO Absorption → Impairment → VAT/NBT/Tax → Net
     * Profit After Tax), built on top of the same account balances plFigures()
     * reads — see 2026_08_20_000001_add_pl_waterfall_accounts_to_chart_of_accounts
     * for where the non-lending lines (Inter Branch Interest, Documentation
     * Income, HO Expense Absorption, VAT/NBT, Income Tax, and Staff Cost's
     * Salary/Allowances split) come from: they're plain accounts booked
     * through the ordinary Journal Entries screen, not computed here.
     */
    private function plWaterfall(?string $from, ?string $to, string $basis): array
    {
        $balances = ChartOfAccount::balancesAsOf($to, $from);
        $bal = fn (string $code) => $balances->get(ChartOfAccount::system($code)->id)->balance ?? '0.00';

        $interestIncome = $basis === 'accrual'
            ? bcadd(AccrualInterestCalculator::incomeForPeriod('Loan', $from, $to), AccrualInterestCalculator::incomeForPeriod('SpeedDraft', $from, $to), 2)
            : bcadd($bal('4010'), $bal('4015'), 2);
        $lendingIncome = bcadd($interestIncome, bcadd($bal('4020'), $bal('4030'), 2), 2);

        $interZonalInterest = $bal('4040');
        $totalIncome = bcadd($lendingIncome, $interZonalInterest, 2);

        $interestCost = $bal('5100');
        $netIncomeExOther = bcsub($totalIncome, $interestCost, 2);

        $documentationIncome = $bal('4910');
        $realEstateProfit = $bal('4920');
        $refundsOnWriteOffs = $bal('4930');
        $otherIncome = bcadd(bcadd($documentationIncome, $realEstateProfit, 2), $refundsOnWriteOffs, 2);
        $netIncome = bcadd($netIncomeExOther, $otherIncome, 2);

        $salary = $bal('5210');
        $allowances = $bal('5220');
        $staffCost = bcadd(bcadd($salary, $allowances, 2), $bal('5200'), 2);
        $otherOperatingExpenses = $bal('5010');
        $profitBeforeHoAbsorption = bcsub(bcsub($netIncome, $staffCost, 2), $otherOperatingExpenses, 2);

        $hoExpAbsorption = $bal('5400');
        $netProfitBeforeTaxImpairment = bcsub($profitBeforeHoAbsorption, $hoExpAbsorption, 2);

        $provisioningForImpairment = $bal('5300');
        // Cash collected against an already-provisioned loan — see
        // LoanLedgerPoster::postProvisionAdjustment(), which credits this
        // instead of reversing straight back into 5300, so a recovery reads
        // as its own P&L gain right next to the impairment charge it offsets,
        // not just a smaller expense number.
        $badDebtRecovery = $bal('4050');
        $netProfitAfterImpairment = bcadd(bcsub($netProfitBeforeTaxImpairment, $provisioningForImpairment, 2), $badDebtRecovery, 2);

        $vatNbt = $bal('5500');
        $incomeTax = $bal('5600');
        $netProfitAfterTax = bcsub(bcsub($netProfitAfterImpairment, $vatNbt, 2), $incomeTax, 2);

        return [
            'lending_income' => $lendingIncome,
            'inter_zonal_interest' => $interZonalInterest,
            'total_income' => $totalIncome,
            'interest_cost' => $interestCost,
            'net_income_ex_other' => $netIncomeExOther,
            'documentation_income' => $documentationIncome,
            'real_estate_profit' => $realEstateProfit,
            'refunds_on_write_offs' => $refundsOnWriteOffs,
            'net_income' => $netIncome,
            'staff_cost' => ['salary' => $salary, 'allowances' => $allowances, 'total' => $staffCost],
            'other_operating_expenses' => $otherOperatingExpenses,
            'profit_before_ho_absorption' => $profitBeforeHoAbsorption,
            'ho_exp_absorption' => $hoExpAbsorption,
            'net_profit_before_tax_impairment' => $netProfitBeforeTaxImpairment,
            'provisioning_for_impairment' => $provisioningForImpairment,
            'bad_debt_recovery' => $badDebtRecovery,
            'net_profit_after_impairment' => $netProfitAfterImpairment,
            'vat_nbt' => $vatNbt,
            'income_tax' => $incomeTax,
            'net_profit_after_tax' => $netProfitAfterTax,
            'net_profit_before_inter_zonal' => bcsub($netProfitAfterTax, $interZonalInterest, 2),
        ];
    }

    /**
     * Interest/Charges/Penalty income (4010/4020/4030) attributed to each
     * loan product (loan_types.name), Cash and Accrual basis side by side —
     * the per-product breakdown table on the P&L. Cash basis is read off
     * journal_lines the same way plFigures() reads 4010/4020/4030 overall,
     * just joined through to loan_types via facility_payments →
     * loan_facility_details rather than left ungrouped; only source_type
     * 'LoanPayment' entries are included, so Speed Draft income (source_type
     * 'SpeedDraftPayment', no loan_type_id to join through anyway) never
     * leaks into this table — it stays on its own 4015 line in the flat
     * income table above. Accrual basis comes from
     * AccrualInterestCalculator::incomeForPeriodByLoanType().
     */
    private function incomeByLoanProduct(?string $from, ?string $to): array
    {
        $accrualByType = AccrualInterestCalculator::incomeForPeriodByLoanType($from, $to);

        $cashRows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('facility_payments', 'facility_payments.id', '=', 'journal_entries.source_id')
            ->join('loan_facility_details', 'loan_facility_details.credit_facility_id', '=', 'facility_payments.credit_facility_id')
            ->leftJoin('loan_types', 'loan_types.id', '=', 'loan_facility_details.loan_type_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            // Posted + Reversed both count — a voided payment's original income line
            // and its reversal net to zero here (see ChartOfAccount::balancesAsOf).
            ->where('journal_entries.source_type', 'LoanPayment')
            ->whereIn('chart_of_accounts.code', ['4010', '4020', '4030'])
            ->when($from, fn ($q, $d) => $q->whereDate('journal_entries.entry_date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->whereDate('journal_entries.entry_date', '<=', $d))
            ->selectRaw("COALESCE(loan_types.name, 'Unclassified') as loan_type_name, chart_of_accounts.code as code, SUM(journal_lines.credit - journal_lines.debit) as amount")
            ->groupBy('loan_type_name', 'code')
            ->get();

        $byType = [];
        foreach ($cashRows as $row) {
            $byType[$row->loan_type_name] ??= ['interest_cash' => '0.00', 'charges' => '0.00', 'penalty' => '0.00'];
            $amount = (string) round((float) $row->amount, 2);
            $key = ['4010' => 'interest_cash', '4020' => 'charges', '4030' => 'penalty'][$row->code];
            $byType[$row->loan_type_name][$key] = bcadd($byType[$row->loan_type_name][$key], $amount, 2);
        }
        foreach ($accrualByType as $name => $amount) {
            $byType[$name] ??= ['interest_cash' => '0.00', 'charges' => '0.00', 'penalty' => '0.00'];
            $byType[$name]['interest_accrual'] = $amount;
        }

        $rows = collect($byType)->map(function (array $d, string $name) {
            $charges = $d['charges'] ?? '0.00';
            $penalty = $d['penalty'] ?? '0.00';
            $interestCash = $d['interest_cash'] ?? '0.00';
            $interestAccrual = $d['interest_accrual'] ?? $interestCash;

            return [
                'loan_type' => $name,
                'interest_cash' => $interestCash,
                'interest_accrual' => $interestAccrual,
                'charges' => $charges,
                'penalty' => $penalty,
                'total_cash' => bcadd(bcadd($interestCash, $charges, 2), $penalty, 2),
                'total_accrual' => bcadd(bcadd($interestAccrual, $charges, 2), $penalty, 2),
            ];
        })->values();

        return $rows->sort(fn ($a, $b) => bccomp($b['total_cash'], $a['total_cash'], 2))->values()->all();
    }

    /**
     * PDF export for every type in TYPES. Each *Pdf() method reuses the same
     * private data-builder the JSON endpoint uses, so the PDF can never
     * disagree with the on-screen report. Rendered through the shared
     * reports.generic Blade view (see ReportExport) — no bespoke views.
     */
    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        [$title, $sections, $subtitle, $meta] = match ($type) {
            'trial-balance' => $this->trialBalancePdf($request),
            'general-ledger' => $this->generalLedgerPdf($request),
            'profit-loss' => $this->profitAndLossPdf($request),
            'balance-sheet' => $this->balanceSheetPdf($request),
            'cash-flow' => $this->cashFlowPdf($request),
            'ledger-drift' => $this->ledgerDriftPdf($request),
        };

        return ReportExport::pdf($title, $sections, $subtitle, $meta)->download(ReportExport::filename($type));
    }

    private function trialBalancePdf(Request $request): array
    {
        $report = $this->trialBalance($request);
        $mapped = collect($report['rows'])->map(fn ($r) => [
            'code' => $r['code'],
            'name' => $r['name'] . ($r['is_abnormal'] ? '  (abnormal balance)' : ''),
            'type' => $r['account_type'],
            'debit' => (float) $r['debit_balance'] > 0 ? $r['debit_balance'] : null,
            'credit' => (float) $r['credit_balance'] > 0 ? $r['credit_balance'] : null,
        ]);
        $sections = [[
            'columns' => [
                ['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Name'], ['key' => 'type', 'label' => 'Type', 'align' => 'center'],
                ['key' => 'debit', 'label' => 'Debit', 'align' => 'right', 'format' => 'money'], ['key' => 'credit', 'label' => 'Credit', 'align' => 'right', 'format' => 'money'],
            ],
            'rows' => $mapped->all(),
            'totalRow' => ['name' => $report['totals']['balanced'] ? 'Totals (balanced)' : 'Totals — OUT OF BALANCE by ' . $report['totals']['difference'], 'debit' => (float) $report['totals']['debit'], 'credit' => (float) $report['totals']['credit']],
            'emptyText' => 'No accounts yet',
        ]];

        return ['Trial Balance', $sections, null, "As of {$report['as_of']}"];
    }

    private function generalLedgerPdf(Request $request): array
    {
        $d = $this->generalLedger($request);
        $mapped = $d['lines']->map(fn ($l) => [
            'date' => $l['entry_date'], 'entry' => $l['journal_entry_code'], 'description' => $l['description'] ?? '—',
            'debit' => (float) $l['debit'] > 0 ? $l['debit'] : null, 'credit' => (float) $l['credit'] > 0 ? $l['credit'] : null, 'balance' => $l['running_balance'],
        ]);
        $sections = [[
            'columns' => [
                ['key' => 'date', 'label' => 'Date'], ['key' => 'entry', 'label' => 'Entry'], ['key' => 'description', 'label' => 'Description'],
                ['key' => 'debit', 'label' => 'Debit', 'align' => 'right', 'format' => 'money'], ['key' => 'credit', 'label' => 'Credit', 'align' => 'right', 'format' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'align' => 'right', 'format' => 'money'],
            ],
            'rows' => $mapped->all(), 'emptyText' => 'No activity in this range',
        ]];
        $from = $request->query('from');
        $to = $request->query('to');

        return ['General Ledger', $sections, $d['account']['code'] . ' — ' . $d['account']['name'], $from || $to ? ('Period: ' . ($from ?: 'Inception') . ' to ' . ($to ?: 'Today')) : null];
    }

    /**
     * (row key, label, indent) for the waterfall PDF, in display order — row
     * key resolves against plWaterfall()'s array via dot-notation for the
     * nested staff_cost figures (data_get()), so the PDF table can never
     * drift out of step with plWaterfall()'s own keys.
     */
    private const WATERFALL_ROWS = [
        ['lending_income', 'Income', 0],
        ['inter_zonal_interest', 'Inter Zonal Interest Income/(Exp)', 0],
        ['total_income', 'Total Income', 0],
        ['interest_cost', 'Interest Cost', 0],
        ['net_income_ex_other', 'Net Income/(Expense) Without Other Income', 0],
        ['documentation_income', 'Documentation Income (Other Income)', 0],
        ['real_estate_profit', 'Real Estate Profit', 0],
        ['refunds_on_write_offs', 'Refunds on Write-offs from General Treasury', 0],
        ['net_income', 'Net Income/(Expense)', 0],
        ['staff_cost.salary', 'Salary/EPF/ETF', 1],
        ['staff_cost.allowances', 'Allowances', 1],
        ['staff_cost.total', 'Staff Cost', 0],
        ['other_operating_expenses', 'Other Operating Expenses', 0],
        ['profit_before_ho_absorption', 'Profit/(Loss) Before Absorption of HO Exp', 0],
        ['ho_exp_absorption', 'HO Exp Absorption', 0],
        ['net_profit_before_tax_impairment', 'Net Profit Before Tax & Impairment', 0],
        ['provisioning_for_impairment', 'Provisioning for Impairment', 0],
        ['bad_debt_recovery', 'Bad Debt Recovery Income', 0],
        ['net_profit_after_impairment', 'Net Profit After Provision for Impairment', 0],
        ['vat_nbt', 'VAT & NBT on Finance Services', 0],
        ['income_tax', 'Income Tax', 0],
        ['net_profit_after_tax', 'Net Profit After Tax', 0],
        ['net_profit_before_inter_zonal', 'Net Profit Before Inter Zonal Interest', 0],
    ];

    private function profitAndLossPdf(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');
        [$income, $expense, $totalIncomeCash, $totalIncomeAccrual, $totalExpense] = $this->plFiguresBothBases($from, $to);
        $netProfitCash = bcsub($totalIncomeCash, $totalExpense, 2);
        $netProfitAccrual = bcsub($totalIncomeAccrual, $totalExpense, 2);
        $waterfallCash = $this->plWaterfall($from, $to, 'cash');
        $waterfallAccrual = $this->plWaterfall($from, $to, 'accrual');
        $byProduct = $this->incomeByLoanProduct($from, $to);

        $sections = [
            [
                'title' => 'P&L Waterfall',
                'columns' => [
                    ['key' => 'label', 'label' => 'Line'],
                    ['key' => 'cash', 'label' => 'Cash Basis', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'accrual', 'label' => 'Accrual Basis', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => collect(self::WATERFALL_ROWS)->map(fn (array $r) => [
                    'label' => str_repeat('    ', $r[2]) . $r[1],
                    'cash' => data_get($waterfallCash, $r[0]),
                    'accrual' => data_get($waterfallAccrual, $r[0]),
                ])->all(),
            ],
            [
                'title' => 'Income by Loan Product',
                'columns' => [
                    ['key' => 'loan_type', 'label' => 'Product'],
                    ['key' => 'interest_cash', 'label' => 'Interest (Cash)', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'interest_accrual', 'label' => 'Interest (Accrual)', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'charges', 'label' => 'Charges', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'penalty', 'label' => 'Penalty', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'total_cash', 'label' => 'Total (Cash)', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => $byProduct,
                'emptyText' => 'No loan payment income posted in this range',
            ],
            [
                'title' => 'Income',
                'columns' => [
                    ['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Name'],
                    ['key' => 'cash', 'label' => 'Cash Basis', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'accrual', 'label' => 'Accrual Basis', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => $income->map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'cash' => $r['cash_balance'], 'accrual' => $r['accrual_balance']])->all(),
                'totalRow' => ['name' => 'Total Income', 'cash' => $totalIncomeCash, 'accrual' => $totalIncomeAccrual],
                'emptyText' => 'No income posted in this range',
            ],
            [
                'title' => 'Expense',
                'columns' => [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Name'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']],
                'rows' => collect($expense)->map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'amount' => $r['balance']])->all(),
                'totalRow' => ['name' => 'Total Expense', 'amount' => $totalExpense],
                'emptyText' => 'No expense posted in this range',
            ],
            [
                'title' => 'Net Profit',
                'columns' => [['key' => 'basis', 'label' => 'Basis'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']],
                'rows' => [['basis' => 'Cash Basis', 'amount' => $netProfitCash], ['basis' => 'Accrual Basis', 'amount' => $netProfitAccrual]],
            ],
        ];

        return ['Profit & Loss Statement', $sections, 'Cash and Accrual Basis', 'Period: ' . ($from ?: 'Inception') . ' to ' . ($to ?: 'Today')];
    }

    private function balanceSheetPdf(Request $request): array
    {
        $d = $this->balanceSheet($request);
        $section = fn (string $title, array $rows, string $total) => [
            'title' => $title,
            'columns' => [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Name'], ['key' => 'balance', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']],
            'rows' => collect($rows)->map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'balance' => $r['balance']])->all(),
            'totalRow' => ['name' => "Total {$title}", 'balance' => $total], 'emptyText' => 'Nothing here yet',
        ];
        $sections = [
            $section('Assets', $d['assets'], $d['total_assets']),
            $section('Liabilities', $d['liabilities'], $d['total_liabilities']),
            $section('Equity', $d['equity'], $d['total_equity']),
            [
                'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right', 'format' => 'money']],
                'rows' => [['metric' => 'Total Liabilities + Equity', 'value' => $d['total_liabilities_and_equity']]],
            ],
        ];

        return ['Balance Sheet', $sections, $d['balanced'] ? 'Balanced' : 'Out of balance', 'As of ' . $d['as_of']];
    }

    private function cashFlowPdf(Request $request): array
    {
        $d = $this->cashFlow($request);
        $section = fn (string $title, array $rows, string $total) => [
            'title' => $title,
            'columns' => [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Name'], ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money']],
            'rows' => $rows, 'totalRow' => ['name' => "Net Cash from {$title}", 'amount' => $total], 'emptyText' => 'No cash movement in this range',
        ];
        $sections = [
            $section('Operating Activities', $d['operating_activities'], $d['net_cash_from_operating']),
            $section('Investing Activities', $d['investing_activities'], $d['net_cash_from_investing']),
            $section('Financing Activities', $d['financing_activities'], $d['net_cash_from_financing']),
            [
                'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right', 'format' => 'money']],
                'rows' => [
                    ['metric' => 'Opening Cash', 'value' => $d['opening_cash']], ['metric' => 'Net Change in Cash', 'value' => $d['net_change_in_cash']], ['metric' => 'Closing Cash', 'value' => $d['closing_cash']],
                ],
            ],
        ];

        return ['Cash Flow Statement', $sections, $d['reconciled'] ? 'Reconciled' : 'Out of balance', 'Period: ' . $d['from'] . ' to ' . $d['to']];
    }

    private function ledgerDriftPdf(Request $request): array
    {
        $d = $this->ledgerDrift($request);
        $mapped = $d['rows']->map(fn ($r) => ['name' => $r['name'], 'kind' => $r['kind'], 'glAccount' => $r['gl_account_code'], 'book' => $r['book_balance'], 'gl' => $r['gl_balance'], 'variance' => $r['variance']]);
        $sections = [[
            'columns' => [
                ['key' => 'name', 'label' => 'Account'], ['key' => 'kind', 'label' => 'Kind', 'align' => 'center'], ['key' => 'glAccount', 'label' => 'GL Account'],
                ['key' => 'book', 'label' => 'Book Balance', 'align' => 'right', 'format' => 'money'], ['key' => 'gl', 'label' => 'GL Balance', 'align' => 'right', 'format' => 'money'], ['key' => 'variance', 'label' => 'Variance', 'align' => 'right', 'format' => 'money'],
            ],
            'rows' => $mapped->all(), 'emptyText' => 'No cash tills or bank accounts linked to the ledger yet',
        ]];

        return ['Ledger Drift', $sections, $d['has_drift'] ? 'Drift found' : 'In agreement', 'As of ' . $d['as_of']];
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

        // A bank account (1020 leaf) with a credit/negative balance is an
        // overdraft drawn — present it under liabilities, not as a negative
        // asset. Moving |balance| off assets and onto liabilities raises both
        // sides equally, so the A = L + E identity below still holds.
        $rows['Asset'] = array_values(array_filter($rows['Asset'], function ($row) use (&$rows, &$totals) {
            if (! preg_match('/^1020(-|$)/', $row['code']) || bccomp($row['balance'], '0', 2) >= 0) {
                return true;
            }
            $drawn = bcmul($row['balance'], '-1', 2);
            $rows['Liability'][] = ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => "Overdraft — {$row['name']}", 'balance' => $drawn];
            $totals['Asset'] = bcsub($totals['Asset'], $row['balance'], 2);
            $totals['Liability'] = bcadd($totals['Liability'], $drawn, 2);

            return false;
        }));

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
     * Direct-method cash flow statement over journal_lines: for every Posted
     * entry in the period that touches Cash in Hand (1010) or Bank Accounts
     * (1020), attribute the *other* line(s) on that same entry — grouped by
     * account and bucketed Operating/Investing/Financing — to a category.
     * Splitting on the non-cash sibling lines (rather than the cash line
     * itself) means a single blended payment entry (e.g. principal + interest
     * + penalty in one loan-payment journal entry) is attributed correctly
     * across accounts without any arbitrary proportional guess: since every
     * entry balances (debits = credits), the sum of the non-cash lines'
     * (credit - debit) always equals exactly the cash side's net movement.
     * Entries with no non-cash sibling (a till<->bank transfer) contribute
     * nothing, which is correct — transfers between cash and cash
     * equivalents are excluded from a cash flow statement.
     */
    private function cashFlow(Request $request): array
    {
        $from = $request->query('from') ?? now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();

        $cashAccountIds = ChartOfAccount::whereIn('code', self::CASH_ACCOUNT_CODES)->pluck('id');

        $entryIds = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            // Posted + Reversed both count — a reversed cash movement and its
            // reversal net to zero (see ChartOfAccount::balancesAsOf).
            ->whereBetween(DB::raw('DATE(journal_entries.entry_date)'), [$from, $to])
            ->whereIn('journal_lines.account_id', $cashAccountIds)
            ->distinct()->pluck('journal_entries.id');

        $lines = DB::table('journal_lines')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_lines.journal_entry_id', $entryIds)
            ->whereNotIn('journal_lines.account_id', $cashAccountIds)
            ->selectRaw('chart_of_accounts.code, chart_of_accounts.name, SUM(journal_lines.credit - journal_lines.debit) as net_amount')
            ->groupBy('chart_of_accounts.code', 'chart_of_accounts.name')
            ->orderBy('chart_of_accounts.code')
            ->get();

        $buckets = ['operating' => [], 'investing' => [], 'financing' => []];
        $totals = ['operating' => '0.00', 'investing' => '0.00', 'financing' => '0.00'];
        foreach ($lines as $line) {
            $amount = round((float) $line->net_amount, 2);
            $key = in_array($line->code, self::INVESTING_ACCOUNT_CODES, true) ? 'investing'
                : (in_array($line->code, self::FINANCING_ACCOUNT_CODES, true) ? 'financing' : 'operating');
            $buckets[$key][] = ['code' => $line->code, 'name' => $line->name, 'amount' => $amount];
            $totals[$key] = bcadd($totals[$key], (string) $amount, 2);
        }

        $netChangeInCash = bcadd(bcadd($totals['operating'], $totals['investing'], 2), $totals['financing'], 2);

        $openingBalances = ChartOfAccount::balancesAsOf(Carbon::parse($from)->subDay()->toDateString());
        $closingBalances = ChartOfAccount::balancesAsOf($to);
        $openingCash = $cashAccountIds->reduce(fn ($sum, $id) => bcadd($sum, $openingBalances->get($id)->balance ?? '0.00', 2), '0.00');
        $closingCash = $cashAccountIds->reduce(fn ($sum, $id) => bcadd($sum, $closingBalances->get($id)->balance ?? '0.00', 2), '0.00');

        return [
            'from' => $from, 'to' => $to,
            'operating_activities' => $buckets['operating'],
            'investing_activities' => $buckets['investing'],
            'financing_activities' => $buckets['financing'],
            'net_cash_from_operating' => $totals['operating'],
            'net_cash_from_investing' => $totals['investing'],
            'net_cash_from_financing' => $totals['financing'],
            'net_change_in_cash' => $netChangeInCash,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            // Sanity check, same idea as Balance Sheet's 'balanced' flag: the sum of
            // the three activity totals must exactly equal the actual change in the
            // cash accounts' own GL balance. A mismatch would mean an entry touched
            // a cash account without a matching non-cash counter-line, which the
            // LedgerPoster's own balance check should never allow.
            'reconciled' => bccomp(bcsub($closingCash, $openingCash, 2), $netChangeInCash, 2) === 0,
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
            if ($basis === 'accrual' && $account->account_type === 'Income') {
                $balance = isset(self::ACCRUAL_INCOME_ACCOUNTS[$account->code])
                    ? AccrualInterestCalculator::incomeForPeriod(self::ACCRUAL_INCOME_ACCOUNTS[$account->code], $from, $to)
                    : '0.00';
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

    /**
     * Cash and Accrual side by side, for the single combined P&L view (no
     * more basis toggle — both figures shown together). Expense never
     * differs by basis (see ACCRUAL_INCOME_ACCOUNTS), so it's computed once
     * and shared; only Income needs a cash_balance/accrual_balance per row.
     */
    private function plFiguresBothBases(?string $from, ?string $to): array
    {
        [$cashIncome, $expense, $totalIncomeCash, $totalExpense] = $this->plFigures($from, $to, 'cash');
        [$accrualIncome, , $totalIncomeAccrual] = $this->plFigures($from, $to, 'accrual');

        $accrualByCode = collect($accrualIncome)->keyBy('code');
        $income = collect($cashIncome)->map(fn (array $row) => [
            'account_id' => $row['account_id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'cash_balance' => $row['balance'],
            'accrual_balance' => $accrualByCode->get($row['code'])['balance'] ?? $row['balance'],
        ])->values();

        return [$income, $expense, $totalIncomeCash, $totalIncomeAccrual, $totalExpense];
    }
}
