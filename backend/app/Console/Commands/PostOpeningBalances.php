<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\Pawning;
use App\Models\SpeedDraft;
use App\Support\LedgerPoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time cutover tool: true-up the control accounts whose real-world
 * balance lives in an operational subledger (the loan / speed-draft /
 * pawning books, and optionally the cash tills / bank accounts) so the
 * general ledger agrees with them.
 *
 * Why it's needed: for a mid-life go-live, loans that were disbursed before
 * the system existed have no Dr 1100 opening entry, yet their in-system
 * repayments still Cr 1100 — so 1100 drifts into a large abnormal credit
 * balance (visible as a negative on the Trial Balance). Same shape for 1110
 * and 1120. This posts a single balanced "OpeningBalance" journal entry
 * that moves each such account to its true figure, with the offset to
 * 3015 Opening Balance Equity.
 *
 * Idempotent in effect: it always posts the *difference* between reality
 * and the current GL balance, so a second run with nothing changed posts
 * nothing. Run it once the ledger is otherwise quiet. Everything it posts
 * is a normal reversible journal entry.
 *
 * NOT covered: FD payable (2010) and accrued interest (1150 / 2020) — the
 * FD subledger needs its own review first (see the FD engine-drift note).
 */
class PostOpeningBalances extends Command
{
    protected $signature = 'ledger:opening-balances
        {--as-of= : Entry date for the opening balance (default: today)}
        {--include-cash : Also true-up every cash till and bank account to its current_balance}
        {--apply : Actually post the entry (without this flag the command only reports what it would do)}';

    protected $description = 'True-up 1100/1110/1120 (and optionally cash/bank) to the operational subledgers via one OpeningBalance journal entry';

    public function handle(): int
    {
        $asOf = $this->option('as-of') ?: now()->toDateString();
        $apply = (bool) $this->option('apply');

        $balances = ChartOfAccount::balancesAsOf($asOf);

        /** @var array<int, array{label:string, account:ChartOfAccount, target:string}> $targets */
        $targets = [];

        foreach ([
            ['1100', 'Loan principal outstanding', fn () => (string) Loan::sum('outstanding_balance_cache')],
            ['1110', 'Speed draft capital outstanding', fn () => (string) SpeedDraft::sum('outstanding_balance_cache')],
            ['1120', 'Pawning capital outstanding', fn () => (string) Pawning::sum('outstanding_balance_cache')],
        ] as [$code, $label, $reality]) {
            $account = ChartOfAccount::where('code', $code)->first();
            if ($account) {
                $targets[] = ['label' => $label, 'account' => $account, 'target' => $reality()];
            }
        }

        if ($this->option('include-cash')) {
            foreach (CashAccount::all() as $till) {
                $targets[] = ['label' => "Cash: {$till->name}", 'account' => ChartOfAccount::forCashTill($till), 'target' => (string) $till->current_balance];
            }
            foreach (BankAccount::all() as $bank) {
                $targets[] = ['label' => "Bank: {$bank->name}", 'account' => ChartOfAccount::forBankAccount($bank), 'target' => (string) $bank->current_balance];
            }
        }

        $lines = [];
        $offsetDebit = '0.00';
        $rows = [];
        foreach ($targets as $t) {
            /** @var ChartOfAccount $account */
            $account = $t['account'];
            // Freshly-created leaf accounts (a till/bank with no postings yet)
            // won't be in the set computed above — treat as a zero balance.
            $current = $balances->get($account->id)?->balance ?? '0.00';
            $deltaNormal = bcsub((string) $t['target'], (string) $current, 2); // in the account's normal-balance orientation
            if (bccomp($deltaNormal, '0.00', 2) === 0) {
                continue;
            }
            // Every account here is Debit-normal, so a positive normal-delta is a debit.
            $debit = bccomp($deltaNormal, '0.00', 2) > 0 ? $deltaNormal : '0.00';
            $credit = bccomp($deltaNormal, '0.00', 2) < 0 ? bcsub('0.00', $deltaNormal, 2) : '0.00';
            $lines[] = ['account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'description' => $t['label']];
            $offsetDebit = bcsub($offsetDebit, $deltaNormal, 2);
            $rows[] = [$account->code, $t['label'], number_format((float) $current, 2), number_format((float) $t['target'], 2), number_format((float) $deltaNormal, 2)];
        }

        if (!$lines) {
            $this->info('Nothing to do — every targeted account already matches its subledger as of ' . $asOf . '.');

            return self::SUCCESS;
        }

        $equity = ChartOfAccount::where('code', '3015')->firstOr(fn () => throw new \RuntimeException('3015 Opening Balance Equity is missing — run migrations.'));
        $lines[] = [
            'account_id' => $equity->id,
            'debit' => bccomp($offsetDebit, '0.00', 2) > 0 ? $offsetDebit : '0.00',
            'credit' => bccomp($offsetDebit, '0.00', 2) < 0 ? bcsub('0.00', $offsetDebit, 2) : '0.00',
            'description' => 'Opening balance offset',
        ];

        $this->table(['Code', 'Account', 'GL now', 'Subledger', 'Adjustment'], $rows);
        $this->line('Offset to 3015 Opening Balance Equity: ' . number_format((float) $offsetDebit, 2) . ' (debit)');

        if (!$apply) {
            $this->warn('Dry run — pass --apply to post this entry.');

            return self::SUCCESS;
        }

        $entry = DB::transaction(fn () => LedgerPoster::post($lines, $asOf, [
            'source_type' => 'OpeningBalance',
            'description' => 'Opening balance true-up (ledger:opening-balances)',
            'posted_by' => null,
        ]));

        $this->info("Posted journal entry {$entry->code} dated {$asOf}.");

        return self::SUCCESS;
    }
}
