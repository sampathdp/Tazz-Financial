<?php

namespace App\Console\Commands;

use App\Models\FinanceTransaction;
use App\Services\NumberGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off: synthesise append-only finance_transactions rows for physical cash
 * / bank movements that happened before the Finance posting service existed —
 * historical cashbook_entries, bank_transactions and cash_transfers. Each new
 * row is linked to that movement's existing journal_entry where there is one
 * (so it never double-counts value — GL stays the source of truth), and
 * tagged meta.backfilled = true.
 *
 * It does NOT move any balance or post any GL — the history already has both.
 * It only makes the past visible in the Finance ledger / reports / audit, and
 * is the prerequisite for a later cutover that turns cashbook_entries /
 * bank_transactions into views over finance_transactions.
 *
 * Dry-run by default. Idempotent — a row already backfilled (matched on
 * idempotency_key) is skipped, so it is safe to re-run.
 *
 * Rows with no journal_entry_id are reported as a residual: their value was
 * never in the GL, so a documented one-off adjustment to 3015 Opening Balance
 * Equity is needed before the view cutover (see the module plan, risk R1).
 */
class BackfillFinanceTransactions extends Command
{
    protected $signature = 'finance:backfill-transactions
        {--apply : write the rows (otherwise dry-run — count only)}
        {--source=all : all|cashbook|bank|transfers}';

    protected $description = 'Synthesise finance_transactions for pre-Finance-module cash/bank history';

    private const CASHBOOK_TXN_TYPE = [
        'Loan Collection' => 'COLLECTION',
        'Expense' => 'EXPENSE_PAYMENT',
        'Transfer' => 'TRANSFER',
        'Deposit' => 'BANK_DEPOSIT',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $source = $this->option('source');
        $created = $skipped = $residual = 0;

        foreach ([
            'cashbook' => fn () => $this->backfillCashbook($apply),
            'bank' => fn () => $this->backfillBank($apply),
            'transfers' => fn () => $this->backfillTransfers($apply),
        ] as $name => $run) {
            if (!in_array($source, ['all', $name], true)) {
                continue;
            }
            [$c, $s, $r] = $run();
            $created += $c;
            $skipped += $s;
            $residual += $r;
        }

        $this->table(['', 'Count'], [
            [$apply ? 'Created' : 'Would create', $created],
            ['Skipped (already backfilled)', $skipped],
            ['Residual (no GL link — needs a 3015 adjustment)', $residual],
        ]);

        if (!$apply) {
            $this->warn('Dry run — nothing written. Re-run with --apply.');
        } else {
            $this->info("Backfill complete: {$created} finance_transactions created.");
        }
        if ($residual > 0) {
            $this->warn("{$residual} historical rows have no journal entry — post a one-off 3015 Opening Balance Equity adjustment for their net before cutting the tables over to views.");
        }

        return self::SUCCESS;
    }

    /** @return array{0:int,1:int,2:int} [created, skipped, residual] */
    private function backfillCashbook(bool $apply): array
    {
        $created = $skipped = $residual = 0;

        DB::table('cashbook_entries')
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', 'FinanceTransaction'))
            ->orderBy('id')
            ->each(function ($row) use ($apply, &$created, &$skipped, &$residual) {
                $key = "backfill-cashbook-{$row->id}";
                if (FinanceTransaction::where('idempotency_key', $key)->exists()) {
                    $skipped++;

                    return;
                }
                if (!$row->journal_entry_id) {
                    $residual++;
                }
                $inbound = $row->direction === 'In';
                $type = self::CASHBOOK_TXN_TYPE[$row->category] ?? ($inbound ? 'CASH_IN' : 'CASH_OUT');
                $this->write($apply, $key, [
                    'txn_type' => $type,
                    'amount' => $row->amount,
                    'branch_id' => $row->branch_id,
                    'journal_entry_id' => $row->journal_entry_id,
                    'executed_at' => $row->entry_date,
                    'executed_by' => $row->created_by,
                    'description' => $row->description ?: 'Backfilled cashbook entry',
                    'source_module' => 'LegacyCashbookEntry',
                    'source_id' => $row->id,
                    'cash' => ['id' => $row->cash_account_id, 'inbound' => $inbound],
                ]);
                $created++;
            });

        return [$created, $skipped, $residual];
    }

    private function backfillBank(bool $apply): array
    {
        $created = $skipped = $residual = 0;
        $credit = ['Deposit', 'TransferIn'];

        DB::table('bank_transactions')
            ->whereNull('finance_transaction_id')
            ->orderBy('id')
            ->each(function ($row) use ($apply, $credit, &$created, &$skipped, &$residual) {
                $key = "backfill-bank-{$row->id}";
                if (FinanceTransaction::where('idempotency_key', $key)->exists()) {
                    $skipped++;

                    return;
                }
                if (!$row->journal_entry_id) {
                    $residual++;
                }
                $inbound = in_array($row->transaction_type, $credit, true);
                $type = ['Deposit' => 'BANK_DEPOSIT', 'Withdrawal' => 'BANK_WITHDRAWAL'][$row->transaction_type] ?? 'TRANSFER';
                $branchId = DB::table('bank_accounts')->where('id', $row->bank_account_id)->value('branch_id');
                $this->write($apply, $key, [
                    'txn_type' => $type,
                    'amount' => $row->amount,
                    'branch_id' => $branchId,
                    'journal_entry_id' => $row->journal_entry_id,
                    'executed_at' => $row->txn_date,
                    'executed_by' => $row->created_by,
                    'description' => $row->description ?: 'Backfilled bank transaction',
                    'source_module' => 'LegacyBankTransaction',
                    'source_id' => $row->id,
                    'bank' => ['id' => $row->bank_account_id, 'inbound' => $inbound],
                ]);
                $created++;

                if ($apply) {
                    DB::table('bank_transactions')->where('id', $row->id)
                        ->update(['finance_transaction_id' => FinanceTransaction::where('idempotency_key', $key)->value('id')]);
                }
            });

        return [$created, $skipped, $residual];
    }

    private function backfillTransfers(bool $apply): array
    {
        $created = $skipped = $residual = 0;

        DB::table('cash_transfers')
            ->whereIn('status', ['Completed', 'Approved'])
            ->orderBy('id')
            ->each(function ($row) use ($apply, &$created, &$skipped, &$residual) {
                $key = "backfill-transfer-{$row->id}";
                if (FinanceTransaction::where('idempotency_key', $key)->exists()) {
                    $skipped++;

                    return;
                }
                $je = DB::table('journal_entries')->where('source_type', 'CashTransfer')->where('source_id', $row->id)->value('id');
                if (!$je) {
                    $residual++;
                }
                $branchId = DB::table('cash_accounts')->where('id', $row->from_cash_account_id)->value('branch_id');
                $this->write($apply, $key, [
                    'txn_type' => 'TRANSFER',
                    'amount' => $row->amount,
                    'branch_id' => $branchId,
                    'journal_entry_id' => $je,
                    'executed_at' => $row->transfer_date,
                    'executed_by' => $row->approved_by ?: $row->requested_by,
                    'description' => $row->description ?: 'Backfilled cash transfer',
                    'source_module' => 'LegacyCashTransfer',
                    'source_id' => $row->id,
                    'transfer' => ['from' => $row->from_cash_account_id, 'to' => $row->to_cash_account_id],
                ]);
                $created++;
            });

        return [$created, $skipped, $residual];
    }

    private function write(bool $apply, string $key, array $spec): void
    {
        if (!$apply) {
            return;
        }

        $source = $dest = ['type' => null, 'id' => null];
        $direction = 'Move';
        if (isset($spec['cash'])) {
            $leg = $spec['cash']['inbound'] ? 'dest' : 'source';
            ${$leg} = ['type' => 'cash', 'id' => $spec['cash']['id']];
            $direction = $spec['cash']['inbound'] ? 'In' : 'Out';
        } elseif (isset($spec['bank'])) {
            $leg = $spec['bank']['inbound'] ? 'dest' : 'source';
            ${$leg} = ['type' => 'bank', 'id' => $spec['bank']['id']];
            $direction = $spec['bank']['inbound'] ? 'In' : 'Out';
        } elseif (isset($spec['transfer'])) {
            $source = ['type' => 'cash', 'id' => $spec['transfer']['from']];
            $dest = ['type' => 'cash', 'id' => $spec['transfer']['to']];
        }

        $code = NumberGenerator::next('finance_transaction');
        $txn = FinanceTransaction::create([
            'code' => $code,
            'txn_type' => $spec['txn_type'],
            'direction' => $direction,
            'source_account_type' => $source['type'],
            'source_account_id' => $source['id'],
            'dest_account_type' => $dest['type'],
            'dest_account_id' => $dest['id'],
            'amount' => $spec['amount'],
            'currency' => 'LKR',
            'branch_id' => $spec['branch_id'],
            'source_module' => $spec['source_module'],
            'source_id' => $spec['source_id'],
            'journal_entry_id' => $spec['journal_entry_id'],
            'status' => 'Completed',
            'idempotency_key' => $key,
            'description' => $spec['description'],
            'meta' => ['backfilled' => true, 'no_gl_link' => empty($spec['journal_entry_id'])],
            'executed_by' => $spec['executed_by'],
            'executed_at' => $spec['executed_at'],
        ]);
        NumberGenerator::attach($code, $txn);
    }
}
