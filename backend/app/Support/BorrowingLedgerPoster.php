<?php

namespace App\Support;

use App\Models\Borrowing;
use App\Models\BorrowingTransaction;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;

/**
 * Bridges company Borrowing cash movements into the general ledger — the
 * liability-side counterpart of FdLedgerPoster. A borrowing brings cash in
 * (Dr the till/bank, Cr the borrowing liability); interest and repayments pay
 * cash back out.
 *
 * Every money leg goes through FinancePostingService (the one money writer):
 * the account's current_balance moves, an immutable finance_transactions row
 * is recorded and (for a till) a cashbook_entries mirror is written —
 * atomically with the GL entry.
 *
 * Every payment method posts: Cash moves a till; Bank (and a Cheque drawn on a
 * named company bank account) moves that bank account via BANK_DEPOSIT /
 * BANK_WITHDRAWAL; a Cheque received with no bank account is parked in 1030
 * Undeposited Funds (same convention as FD deposits). An Overdraft-type
 * borrowing posts nothing on creation at all: it only registers the facility,
 * and the draw-downs are ordinary bank withdrawals posted by the Bank module.
 */
class BorrowingLedgerPoster
{
    /** The liability control account for a borrowing's type. */
    public static function liabilityAccount(Borrowing $borrowing): ChartOfAccount
    {
        return ChartOfAccount::system(match ($borrowing->type) {
            'Overdraft' => '2210',
            'RePledgedPawning' => '2130',
            default => '2200',
        });
    }

    /**
     * The physical account a movement hits. Cash -> the named till (else the
     * branch primary); Bank, and a Cheque drawn on a named company bank
     * account -> that bank account's ledger leaf. Null when nothing resolves.
     *
     * @return array{type: string, id: int, leaf: ChartOfAccount}|null
     */
    private static function leg(?string $method, int $branchId, ?int $cashAccountId, ?int $bankAccountId): ?array
    {
        if ($method === 'Cash') {
            $till = $cashAccountId ? CashAccount::find($cashAccountId) : CashAccount::branchPrimary($branchId);

            return $till ? ['type' => 'cash', 'id' => (int) $till->id, 'leaf' => ChartOfAccount::forCashTill($till)] : null;
        }

        return MoneyLeg::resolve($method, $branchId, CashAccount::PURPOSE_FD, $bankAccountId);
    }

    private static function requireLeg(?array $leg, ?string $method): array
    {
        if ($leg) {
            return $leg;
        }
        throw new \App\Exceptions\FinancePostingException(
            $method === 'Cash'
                ? 'This branch has no active cash account, so the cash movement cannot be recorded. Add a till under Cashbook > Accounts first.'
                : 'Select the company bank account this ' . strtolower((string) $method) . ' movement goes through, so it can be recorded.'
        );
    }

    /** Cash borrowed in when the borrowing is recorded — Dr the till/bank (or 1030 for an unbanked cheque), Cr the borrowing liability. */
    public static function postDrawdown(Borrowing $borrowing): void
    {
        self::postReceipt(
            $borrowing, $borrowing->receipt_method, $borrowing->receipt_cash_account_id, $borrowing->receipt_bank_account_id,
            (string) $borrowing->principal_amount, $borrowing->borrowing_date, 'Borrowing', $borrowing->id,
            "borrowing-drawdown-{$borrowing->id}", "Borrowing received - {$borrowing->borrowing_number} ({$borrowing->lender_name})",
            $borrowing->created_by
        );
    }

    public static function reverseDrawdown(Borrowing $borrowing, ?int $userId = null): void
    {
        self::reverseVia('Borrowing', $borrowing->id, "Voided: {$borrowing->borrowing_number}", $userId);
    }

    /** Money in against the liability — shared by the opening drawdown and later top-up Drawdown transactions. */
    private static function postReceipt(Borrowing $borrowing, ?string $method, ?int $cashAccountId, ?int $bankAccountId, string $amount, $date, string $sourceType, int $sourceId, string $key, string $description, ?int $userId): void
    {
        if (!$method) {
            return;
        }
        $liability = self::liabilityAccount($borrowing);
        $branchId = (int) $borrowing->branch_id;
        $dateStr = Carbon::parse($date)->toDateString();

        // A cheque received but not yet banked: the money is owed but not in any account — park it in 1030.
        if ($method === 'Cheque' && !$bankAccountId) {
            LedgerPoster::post([
                ['account_id' => ChartOfAccount::system('1030')->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $liability->id, 'debit' => 0, 'credit' => $amount],
            ], $dateStr, [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description . ' (cheque, undeposited)',
                'branch_id' => $branchId,
                'posted_by' => $userId,
            ]);

            return;
        }

        $leg = self::requireLeg(self::leg($method, $branchId, $cashAccountId, $bankAccountId), $method);
        self::postCashLeg($leg, '+' . $amount, [
            ['account_id' => $leg['leaf']->id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $liability->id, 'debit' => 0, 'credit' => $amount],
        ], [
            'txn_type' => 'CASH_IN',
            'amount' => $amount,
            'entry_date' => $dateStr,
            'branch_id' => $branchId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'idempotency_key' => $key,
            'description' => $description,
            'posted_by' => $userId,
        ]);
    }

    /** A transaction against a borrowing — Cash, Bank or Cheque (a cheque paid out must name the bank account it is drawn on). */
    public static function postTransaction(BorrowingTransaction $txn): void
    {
        $borrowing = $txn->borrowing;
        $description = "Borrowing {$txn->kind} - {$borrowing->borrowing_number} ({$txn->voucher_no})";
        $amount = (string) $txn->amount;

        if ($txn->kind === 'Drawdown') {
            self::postReceipt(
                $borrowing, $txn->payment_method, $txn->cash_account_id, $txn->bank_account_id, $amount, $txn->txn_date,
                'BorrowingTransaction', $txn->id, "borrowing-txn-{$txn->id}", $description, $txn->created_by
            );

            return;
        }

        $leg = self::requireLeg(
            self::leg($txn->payment_method, (int) $borrowing->branch_id, $txn->cash_account_id, $txn->bank_account_id),
            $txn->payment_method
        );
        if ($txn->kind === 'InterestPayment') {
            // Clear what was already accrued to 2220 first; only the rest is new expense (5110).
            $cleared = (string) $txn->accrued_interest_cleared;
            $expense = bcsub($amount, $cleared, 2);
            $debits = [];
            if (bccomp($cleared, '0', 2) > 0) {
                $debits[] = ['account_id' => ChartOfAccount::system('2220')->id, 'debit' => $cleared, 'credit' => 0];
            }
            if (bccomp($expense, '0', 2) > 0) {
                $debits[] = ['account_id' => ChartOfAccount::system('5110')->id, 'debit' => $expense, 'credit' => 0];
            }
        } else { // PrincipalRepayment, Settlement
            $debits = [['account_id' => self::liabilityAccount($borrowing)->id, 'debit' => $amount, 'credit' => 0]];
        }

        self::postCashLeg($leg, '-' . $amount, array_merge($debits, [
            ['account_id' => $leg['leaf']->id, 'debit' => 0, 'credit' => $amount],
        ]), [
            'txn_type' => 'CASH_OUT',
            'amount' => $amount,
            'entry_date' => $txn->txn_date,
            'branch_id' => $borrowing->branch_id,
            'source_type' => 'BorrowingTransaction',
            'source_id' => $txn->id,
            'idempotency_key' => "borrowing-txn-{$txn->id}",
            'description' => $description,
            'posted_by' => $txn->created_by,
        ]);
    }

    /** Interest earned by the lender but not yet paid — Dr 5110, Cr 2220 Accrued Interest Payable. No cash moves. */
    public static function postAccrual(Borrowing $borrowing, string $amount, string $date, string $period, ?int $userId = null): void
    {
        LedgerPoster::post([
            ['account_id' => ChartOfAccount::system('5110')->id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => ChartOfAccount::system('2220')->id, 'debit' => 0, 'credit' => $amount],
        ], $date, [
            'source_type' => 'BorrowingAccrual',
            'source_id' => $borrowing->id,
            'description' => "Borrowing interest accrued - {$borrowing->borrowing_number} ({$period})",
            'branch_id' => $borrowing->branch_id,
            'posted_by' => $userId,
        ]);
    }

    /** Reverses every accrual entry of a borrowing — used when the borrowing itself is deleted. */
    public static function reverseAccruals(Borrowing $borrowing, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'BorrowingAccrual')
            ->where('source_id', $borrowing->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Deleted: {$borrowing->borrowing_number}", $userId));
    }

    public static function reverseTransaction(BorrowingTransaction $txn, ?int $userId = null): void
    {
        self::reverseVia('BorrowingTransaction', $txn->id, "Voided: {$txn->voucher_no}", $userId);
    }

    /**
     * Books one borrowing money leg through the one money writer. $glLines is
     * the full balanced entry (till/bank leg included); $delta is the signed
     * account movement; $ctx carries the shared spec fields.
     */
    private static function postCashLeg(array $leg, string $delta, array $glLines, array $ctx): void
    {
        FinancePostingService::execute([
            'txn_type' => MoneyLeg::txnType($leg, $ctx['txn_type']),
            'amount' => $ctx['amount'],
            'entry_date' => $ctx['entry_date'] instanceof \DateTimeInterface
                ? $ctx['entry_date']->format('Y-m-d')
                : (string) Carbon::parse($ctx['entry_date'])->toDateString(),
            'branch_id' => $ctx['branch_id'],
            'movements' => [['account_type' => $leg['type'], 'account_id' => $leg['id'], 'delta' => $delta]],
            'gl_lines' => $glLines,
            'gl_source_type' => $ctx['source_type'],
            'gl_source_id' => $ctx['source_id'],
            'gl_description' => $ctx['description'],
            'description' => $ctx['description'],
            'source_module' => $ctx['source_type'],
            'source_id' => $ctx['source_id'],
            'idempotency_key' => $ctx['idempotency_key'],
            'executed_by' => $ctx['posted_by'],
        ]);
    }

    /**
     * Undoes a borrowing leg. Prefers FinancePostingService::reverse()
     * (restores the account + posts the mirrored GL reversal + leaves a
     * REVERSAL row), falling back to a GL-only reversal for a leg posted
     * without the Finance posting service (an unbanked cheque in 1030, or one
     * posted before that service existed). No-op if nothing was posted.
     */
    private static function reverseVia(string $sourceType, int $sourceId, string $reason, ?int $userId): void
    {
        $txn = FinanceTransaction::where('source_module', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('reversal_of_id')
            ->where('status', 'Completed')
            ->latest('id')
            ->first();
        if ($txn) {
            FinancePostingService::reverse($txn, $reason, $userId);

            return;
        }

        JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, $reason, $userId));
    }
}
