<?php

namespace App\Support;

use App\Models\Borrowing;
use App\Models\BorrowingTransaction;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;

/**
 * Bridges company Borrowing cash movements into the general ledger — the
 * liability-side counterpart of FdLedgerPoster. A borrowing brings cash in
 * (Dr the till, Cr the borrowing liability); interest and repayments pay cash
 * back out.
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

        LedgerPoster::post([
            ['account_id' => $tillAccount->id, 'debit' => $borrowing->principal_amount, 'credit' => 0],
            ['account_id' => $liability->id, 'debit' => 0, 'credit' => $borrowing->principal_amount],
        ], $borrowing->borrowing_date, [
            'source_type' => 'Borrowing',
            'source_id' => $borrowing->id,
            'description' => "Borrowing received - {$borrowing->borrowing_number} ({$borrowing->lender_name})",
            'branch_id' => $borrowing->branch_id,
            'posted_by' => $borrowing->created_by,
        ]);
    }

    public static function reverseDrawdown(Borrowing $borrowing, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'Borrowing')
            ->where('source_id', $borrowing->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided: {$borrowing->borrowing_number}", $userId));
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

        $lines = match ($txn->kind) {
            'Drawdown' => [
                ['account_id' => $tillAccount->id, 'debit' => $txn->amount, 'credit' => 0],
                ['account_id' => $liability->id, 'debit' => 0, 'credit' => $txn->amount],
            ],
            'InterestPayment' => [
                ['account_id' => ChartOfAccount::system('5110')->id, 'debit' => $txn->amount, 'credit' => 0],
                ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $txn->amount],
            ],
            default => [ // PrincipalRepayment, Settlement
                ['account_id' => $liability->id, 'debit' => $txn->amount, 'credit' => 0],
                ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $txn->amount],
            ],
        };

        LedgerPoster::post($lines, $txn->txn_date, [
            'source_type' => 'BorrowingTransaction',
            'source_id' => $txn->id,
            'description' => "Borrowing {$txn->kind} - {$borrowing->borrowing_number} ({$txn->voucher_no})",
            'branch_id' => $borrowing->branch_id,
            'posted_by' => $txn->created_by,
        ]);
    }

    public static function reverseTransaction(BorrowingTransaction $txn, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'BorrowingTransaction')
            ->where('source_id', $txn->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided: {$txn->voucher_no}", $userId));
    }
}
