<?php

namespace App\Support;

use App\Exceptions\FinancePostingException;
use App\Exceptions\InsufficientFundsException;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\FinanceAuditEvent;
use App\Models\FinanceTransaction;
use App\Services\NumberGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single writer for physical money movement — the only code allowed to
 * change a cash_accounts / bank_accounts current_balance. Every finance flow
 * (disbursement, expense payment, transfer, float issue, collection, ...)
 * calls execute() rather than hand-rolling "lock the account, bcadd, write a
 * cashbook row, post the ledger" on its own, the way each *Poster class does
 * today.
 *
 * execute() does, atomically, in one DB transaction:
 *   1. idempotency check — a replayed key returns the original transaction,
 *      never a second movement;
 *   2. lock every touched cash/bank account in a stable order;
 *   3. validate available balance on each outbound leg (unless the cash
 *      account is allow_negative, or the bank account stays within its
 *      overdraft_limit);
 *   4. post the balanced general-ledger entry via LedgerPoster (still the
 *      one GL writer);
 *   5. move current_balance on each account;
 *   6. insert the immutable finance_transactions row, linked 1:1 to that
 *      journal entry;
 *
 * GL is the source of truth for value; current_balance is the reconciled
 * cache this service keeps in step, re-asserted by a scheduled drift check.
 */
class FinancePostingService
{
    /**
     * @param array{
     *   txn_type: string,
     *   amount: numeric,
     *   entry_date: string,
     *   gl_lines: array<int, array{account_id:int, debit?:numeric, credit?:numeric, description?:?string}>,
     *   movements: array<int, array{account_type:string, account_id:int, delta:numeric}>,
     *   branch_id?: ?int,
     *   source_module?: ?string,
     *   source_id?: ?int,
     *   finance_request_id?: ?int,
     *   description?: ?string,
     *   gl_description?: ?string,
     *   gl_source_type?: ?string,
     *   gl_source_id?: ?int,
     *   meta?: ?array,
     *   idempotency_key?: ?string,
     *   status?: string,
     *   currency?: string,
     *   requested_by?: ?int,
     *   approved_by?: ?int,
     *   executed_by?: ?int,
     *   received_by?: ?int
     * } $spec
     */
    public static function execute(array $spec): FinanceTransaction
    {
        $spec = self::normalize($spec);

        return DB::transaction(function () use ($spec) {
            if ($spec['idempotency_key']) {
                $existing = FinanceTransaction::where('idempotency_key', $spec['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            // 2 + 3 + 5 — lock, validate, move, in a deterministic account order.
            $movements = collect($spec['movements'])
                ->sortBy(fn ($m) => $m['account_type'] . ':' . str_pad((string) $m['account_id'], 12, '0', STR_PAD_LEFT))
                ->values();

            $applied = [];
            foreach ($movements as $m) {
                $newBalance = self::applyMovement($m['account_type'], (int) $m['account_id'], (string) $m['delta']);
                $applied[] = ['type' => $m['account_type'], 'id' => (int) $m['account_id'], 'delta' => (string) $m['delta'], 'balance' => $newBalance];
            }

            // 4 — the GL entry, still through the one ledger writer. By default
            // it is sourced as this finance transaction; a caller that needs
            // the entry to stay keyed to its own record (e.g. Loan
            // disbursement — so LoanController::purge()/reverseDisbursement()
            // still find it by source_type='Loan') passes gl_source_type /
            // gl_source_id instead.
            $glSourceType = $spec['gl_source_type'] ?? 'FinanceTransaction';
            $backfillGlSource = !isset($spec['gl_source_type']);
            $entry = LedgerPoster::post($spec['gl_lines'], $spec['entry_date'], [
                'source_type' => $glSourceType,
                'source_id' => $spec['gl_source_id'] ?? null, // back-filled below when it is this txn
                'description' => $spec['gl_description'] ?? $spec['description'],
                'branch_id' => $spec['branch_id'],
                'posted_by' => $spec['executed_by'],
            ]);

            // 6 — the immutable record.
            $code = NumberGenerator::next('finance_transaction');
            [$source, $dest] = self::endpoints($movements);

            $txn = FinanceTransaction::create([
                'code' => $code,
                'txn_type' => $spec['txn_type'],
                'direction' => self::direction($movements),
                'source_account_type' => $source['account_type'] ?? null,
                'source_account_id' => $source['account_id'] ?? null,
                'dest_account_type' => $dest['account_type'] ?? null,
                'dest_account_id' => $dest['account_id'] ?? null,
                'amount' => $spec['amount'],
                'currency' => $spec['currency'],
                'branch_id' => $spec['branch_id'],
                'finance_request_id' => $spec['finance_request_id'],
                'source_module' => $spec['source_module'],
                'source_id' => $spec['source_id'],
                'journal_entry_id' => $entry->id,
                'status' => $spec['status'],
                'idempotency_key' => $spec['idempotency_key'],
                'description' => $spec['description'],
                'meta' => $spec['meta'],
                'requested_by' => $spec['requested_by'],
                'approved_by' => $spec['approved_by'],
                'executed_by' => $spec['executed_by'],
                'received_by' => $spec['received_by'],
                'executed_at' => now(),
            ]);
            NumberGenerator::attach($code, $txn);
            if ($backfillGlSource) {
                $entry->update(['source_id' => $txn->id]);
            }

            // Mirror each cash-account leg into cashbook_entries so the
            // Cashbook screen still shows every movement. This is a bridge:
            // Phase 9 turns cashbook_entries into a view over
            // finance_transactions and drops this write.
            foreach ($applied as $leg) {
                if ($leg['type'] === 'cash') {
                    self::mirrorToCashbook($txn, $entry->id, $leg);
                }
            }

            FinanceAuditEvent::record('transaction.executed', $txn, [
                'actor_id' => $spec['executed_by'],
                'amount' => $txn->amount,
                'branch_id' => $txn->branch_id,
                'to_status' => $txn->status,
                'meta' => ['txn_type' => $txn->txn_type, 'code' => $txn->code, 'source' => $txn->source_module],
            ]);

            return $txn;
        });
    }

    private const CASHBOOK_CATEGORY = [
        'COLLECTION' => 'Loan Collection',
        'EXPENSE_PAYMENT' => 'Expense',
        'TRANSFER' => 'Transfer', 'FLOAT_ISSUE' => 'Transfer', 'FLOAT_RETURN' => 'Transfer',
        'CASH_IN' => 'Other', 'CASH_OUT' => 'Other', 'DISBURSEMENT' => 'Other',
        'BANK_DEPOSIT' => 'Deposit', 'BANK_WITHDRAWAL' => 'Other', 'ADJUSTMENT' => 'Other', 'REVERSAL' => 'Other',
    ];

    /** @param array{type:string,id:int,delta:string,balance:string} $leg */
    private static function mirrorToCashbook(FinanceTransaction $txn, ?int $journalEntryId, array $leg): void
    {
        $account = CashAccount::find($leg['id']);
        if (!$account) {
            return;
        }
        $inbound = bccomp($leg['delta'], '0.00', 2) >= 0;
        // A manual adjustment carries the operator's chosen category in meta;
        // otherwise it is derived from the transaction type.
        $category = $txn->meta['cashbook_category']
            ?? self::CASHBOOK_CATEGORY[$txn->txn_type]
            ?? 'Other';
        \App\Models\CashbookEntry::create([
            'branch_id' => $account->branch_id,
            'cash_account_id' => $account->id,
            'journal_entry_id' => $journalEntryId,
            'entry_date' => $txn->executed_at?->toDateString() ?? now()->toDateString(),
            'direction' => $inbound ? 'In' : 'Out',
            'category' => $category,
            'amount' => ltrim($leg['delta'], '-'),
            'balance_after' => $leg['balance'],
            'source_type' => 'FinanceTransaction',
            'source_id' => $txn->id,
            'description' => $txn->description ?? $txn->code,
            'created_by' => $txn->executed_by,
        ]);
    }

    /**
     * Undo a completed transaction: inverse balance movements, a mirrored GL
     * entry via LedgerPoster::reverse(), and a linked REVERSAL row. Both rows
     * stay visible; the original flips to Reversed. Never deletes anything.
     */
    public static function reverse(FinanceTransaction $txn, string $reason, ?int $userId = null): FinanceTransaction
    {
        if ($txn->status === 'Reversed') {
            throw new FinancePostingException('This transaction has already been reversed.');
        }
        if ($txn->reversal_of_id) {
            throw new FinancePostingException('A reversal entry cannot itself be reversed.');
        }

        return DB::transaction(function () use ($txn, $reason, $userId) {
            $inverse = [];
            foreach ([['source_account_type', 'source_account_id'], ['dest_account_type', 'dest_account_id']] as [$typeCol, $idCol]) {
                if ($txn->$typeCol && $txn->$idCol) {
                    // Original source lost money -> give it back; original dest gained -> take it back.
                    $sign = $typeCol === 'source_account_type' ? 1 : -1;
                    $inverse[] = ['account_type' => $txn->$typeCol, 'account_id' => (int) $txn->$idCol, 'delta' => bcmul((string) $txn->amount, (string) $sign, 2)];
                }
            }
            $applied = [];
            foreach (collect($inverse)->sortBy(fn ($m) => $m['account_type'] . ':' . str_pad((string) $m['account_id'], 12, '0', STR_PAD_LEFT)) as $m) {
                $bal = self::applyMovement($m['account_type'], (int) $m['account_id'], (string) $m['delta'], allowNegativeOverride: true);
                $applied[] = ['type' => $m['account_type'], 'id' => (int) $m['account_id'], 'delta' => (string) $m['delta'], 'balance' => $bal];
            }

            $reversalEntry = $txn->journalEntry
                ? LedgerPoster::reverse($txn->journalEntry, "Reversal: {$txn->code} — {$reason}", $userId)
                : null;

            $code = NumberGenerator::next('finance_transaction');
            $reversal = FinanceTransaction::create([
                'code' => $code,
                'txn_type' => 'REVERSAL',
                'direction' => $txn->direction === 'In' ? 'Out' : ($txn->direction === 'Out' ? 'In' : 'Move'),
                'source_account_type' => $txn->dest_account_type,
                'source_account_id' => $txn->dest_account_id,
                'dest_account_type' => $txn->source_account_type,
                'dest_account_id' => $txn->source_account_id,
                'amount' => $txn->amount,
                'currency' => $txn->currency,
                'branch_id' => $txn->branch_id,
                'source_module' => $txn->source_module,
                'source_id' => $txn->source_id,
                'journal_entry_id' => $reversalEntry?->id,
                'status' => 'Completed',
                'reversal_of_id' => $txn->id,
                'description' => "Reversal of {$txn->code}: {$reason}",
                'executed_by' => $userId,
                'executed_at' => now(),
            ]);
            NumberGenerator::attach($code, $reversal);

            foreach ($applied as $leg) {
                if ($leg['type'] === 'cash') {
                    self::mirrorToCashbook($reversal, $reversalEntry?->id, $leg);
                }
            }

            $txn->update(['status' => 'Reversed']);

            FinanceAuditEvent::record('transaction.reversed', $txn, [
                'actor_id' => $userId,
                'amount' => $txn->amount,
                'branch_id' => $txn->branch_id,
                'from_status' => 'Completed',
                'to_status' => 'Reversed',
                'reason' => $reason,
                'meta' => ['reversal_code' => $reversal->code, 'original_code' => $txn->code],
            ]);

            return $reversal;
        });
    }

    /** Locks one cash/bank account, applies a signed delta, enforces the no-negative rule, and returns the new balance. */
    private static function applyMovement(string $type, int $id, string $delta, bool $allowNegativeOverride = false): string
    {
        if ($type === 'cash') {
            $account = CashAccount::lockForUpdate()->find($id);
            if (!$account) {
                throw new FinancePostingException("Cash account #{$id} not found.");
            }
            $newBalance = bcadd((string) $account->current_balance, $delta, 2);
            if (!$allowNegativeOverride && !$account->allow_negative && bccomp($newBalance, '0.00', 2) < 0) {
                throw new InsufficientFundsException("{$account->name} does not have enough cash for this transaction (balance would be " . number_format((float) $newBalance, 2) . ').');
            }
            $account->update(['current_balance' => $newBalance]);

            return $newBalance;
        }

        if ($type === 'bank') {
            $account = BankAccount::lockForUpdate()->find($id);
            if (!$account) {
                throw new FinancePostingException("Bank account #{$id} not found.");
            }
            $newBalance = bcadd((string) $account->current_balance, $delta, 2);
            $floor = bcmul((string) ($account->overdraft_limit ?? '0'), '-1', 2);
            if (!$allowNegativeOverride && bccomp($newBalance, $floor, 2) < 0) {
                throw new InsufficientFundsException("{$account->name} does not have enough available balance for this transaction (would exceed its overdraft limit).");
            }
            $account->update(['current_balance' => $newBalance]);

            return $newBalance;
        }

        throw new FinancePostingException("Unknown account type '{$type}'.");
    }

    private static function normalize(array $spec): array
    {
        foreach (['txn_type', 'amount', 'entry_date', 'gl_lines', 'movements'] as $required) {
            if (!array_key_exists($required, $spec)) {
                throw new FinancePostingException("Finance transaction spec is missing '{$required}'.");
            }
        }
        if (!in_array($spec['txn_type'], FinanceTransaction::TXN_TYPES, true)) {
            throw new FinancePostingException("Unknown finance transaction type '{$spec['txn_type']}'.");
        }
        if (bccomp((string) $spec['amount'], '0.00', 2) <= 0) {
            throw new FinancePostingException('A finance transaction amount must be greater than zero.');
        }
        if (count($spec['movements']) < 1) {
            throw new FinancePostingException('A finance transaction must move at least one account.');
        }
        if (count($spec['movements']) > 2) {
            throw new FinancePostingException('A finance transaction moves at most two accounts (a transfer).');
        }

        return $spec + [
            'branch_id' => null, 'source_module' => null, 'source_id' => null, 'finance_request_id' => null,
            'description' => null, 'gl_description' => null, 'meta' => null, 'idempotency_key' => null,
            'status' => 'Completed', 'currency' => 'LKR',
            'requested_by' => null, 'approved_by' => null, 'executed_by' => null, 'received_by' => null,
        ];
    }

    /** 'In' (one inbound leg), 'Out' (one outbound leg), or 'Move' (a transfer with both). */
    private static function direction(Collection $movements): string
    {
        if ($movements->count() === 2) {
            return 'Move';
        }
        $delta = (string) $movements->first()['delta'];

        return bccomp($delta, '0.00', 2) < 0 ? 'Out' : 'In';
    }

    /** [sourceLeg, destLeg] — the outbound account is the source, the inbound account is the dest. */
    private static function endpoints(Collection $movements): array
    {
        $outbound = $movements->first(fn ($m) => bccomp((string) $m['delta'], '0.00', 2) < 0);
        $inbound = $movements->first(fn ($m) => bccomp((string) $m['delta'], '0.00', 2) > 0);

        return [$outbound ?? [], $inbound ?? []];
    }
}
