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
 * (Dr the till, Cr the borrowing liability); interest and repayments pay cash
 * back out.
 *
 * Since Phase 9 every cash leg goes through FinancePostingService (the one
 * money writer): the till's current_balance moves, an immutable
 * finance_transactions row is recorded and a cashbook_entries mirror is
 * written — atomically with the GL entry. The separate BorrowingCashbookPoster
 * that used to move the till on its own is gone.
 *
 * Scope, deliberately: only Cash-method movements post here, same reasoning as
 * every other poster in this codebase — Bank/Cheque have no unambiguous
 * account to move without going through the Bank module. An Overdraft-type
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

    private static function till(int $branchId, ?int $cashAccountId): ?CashAccount
    {
        if ($cashAccountId) {
            return CashAccount::find($cashAccountId);
        }

        return CashAccount::branchPrimary($branchId);
    }

    /** Cash borrowed in when the borrowing is recorded — Dr the till, Cr the borrowing liability. */
    public static function postDrawdown(Borrowing $borrowing): void
    {
        if ($borrowing->receipt_method !== 'Cash') {
            return;
        }
        $till = self::till((int) $borrowing->branch_id, $borrowing->receipt_cash_account_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $liability = self::liabilityAccount($borrowing);

        self::postCashLeg($till, '+' . $borrowing->principal_amount, [
            ['account_id' => $tillAccount->id, 'debit' => $borrowing->principal_amount, 'credit' => 0],
            ['account_id' => $liability->id, 'debit' => 0, 'credit' => $borrowing->principal_amount],
        ], [
            'txn_type' => 'CASH_IN',
            'amount' => $borrowing->principal_amount,
            'entry_date' => $borrowing->borrowing_date,
            'branch_id' => $borrowing->branch_id,
            'source_type' => 'Borrowing',
            'source_id' => $borrowing->id,
            'idempotency_key' => "borrowing-drawdown-{$borrowing->id}",
            'description' => "Borrowing received - {$borrowing->borrowing_number} ({$borrowing->lender_name})",
            'posted_by' => $borrowing->created_by,
        ]);
    }

    public static function reverseDrawdown(Borrowing $borrowing, ?int $userId = null): void
    {
        self::reverseVia('Borrowing', $borrowing->id, "Voided: {$borrowing->borrowing_number}", $userId);
    }

    /** A Cash-method transaction against a borrowing. */
    public static function postTransaction(BorrowingTransaction $txn): void
    {
        if ($txn->payment_method !== 'Cash') {
            return;
        }
        $borrowing = $txn->borrowing;
        $till = self::till((int) $borrowing->branch_id, $txn->cash_account_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $liability = self::liabilityAccount($borrowing);

        [$lines, $delta, $txnType] = match ($txn->kind) {
            'Drawdown' => [[
                ['account_id' => $tillAccount->id, 'debit' => $txn->amount, 'credit' => 0],
                ['account_id' => $liability->id, 'debit' => 0, 'credit' => $txn->amount],
            ], '+' . $txn->amount, 'CASH_IN'],
            'InterestPayment' => [[
                ['account_id' => ChartOfAccount::system('5110')->id, 'debit' => $txn->amount, 'credit' => 0],
                ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $txn->amount],
            ], '-' . $txn->amount, 'CASH_OUT'],
            default => [[ // PrincipalRepayment, Settlement
                ['account_id' => $liability->id, 'debit' => $txn->amount, 'credit' => 0],
                ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $txn->amount],
            ], '-' . $txn->amount, 'CASH_OUT'],
        };

        self::postCashLeg($till, $delta, $lines, [
            'txn_type' => $txnType,
            'amount' => $txn->amount,
            'entry_date' => $txn->txn_date,
            'branch_id' => $borrowing->branch_id,
            'source_type' => 'BorrowingTransaction',
            'source_id' => $txn->id,
            'idempotency_key' => "borrowing-txn-{$txn->id}",
            'description' => "Borrowing {$txn->kind} - {$borrowing->borrowing_number} ({$txn->voucher_no})",
            'posted_by' => $txn->created_by,
        ]);
    }

    public static function reverseTransaction(BorrowingTransaction $txn, ?int $userId = null): void
    {
        self::reverseVia('BorrowingTransaction', $txn->id, "Voided: {$txn->voucher_no}", $userId);
    }

    /**
     * Books one borrowing cash leg through the one money writer. $glLines is
     * the full balanced entry (till leg included); $delta is the signed till
     * movement; $ctx carries the shared spec fields.
     */
    private static function postCashLeg(CashAccount $till, string $delta, array $glLines, array $ctx): void
    {
        FinancePostingService::execute([
            'txn_type' => $ctx['txn_type'],
            'amount' => $ctx['amount'],
            'entry_date' => $ctx['entry_date'] instanceof \DateTimeInterface
                ? $ctx['entry_date']->format('Y-m-d')
                : (string) Carbon::parse($ctx['entry_date'])->toDateString(),
            'branch_id' => $ctx['branch_id'],
            'movements' => [['account_type' => 'cash', 'account_id' => (int) $till->id, 'delta' => $delta]],
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
     * Undoes a Cash-method borrowing leg. Prefers FinancePostingService::
     * reverse() (restores the till + posts the mirrored GL reversal + leaves a
     * REVERSAL row), falling back to a GL-only reversal for a leg posted
     * before the Finance posting service existed. No-op if nothing was posted.
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
