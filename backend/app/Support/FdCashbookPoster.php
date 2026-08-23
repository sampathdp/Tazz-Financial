<?php

namespace App\Support;

use App\Models\CashAccount;
use App\Models\CashbookEntry;
use App\Models\FdDeposit;
use App\Models\FdInterestPayment;
use App\Models\FdPartPayment;
use App\Models\FdWithdrawal;
use Illuminate\Support\Facades\DB;

/**
 * Bridges Fixed Deposit cash movements into the Cashbook ledger — the FD
 * counterpart of CashbookPoster (which only ever handles cash coming IN for
 * a loan receipt). FDs move cash both ways: a deposit brings cash in, while
 * a monthly interest payout or a closing withdrawal pays cash back out — so
 * this is a separate class with a method per direction, rather than
 * stretching CashbookPoster to cover a shape it wasn't built for.
 *
 * Only Cash-method movements post here, same "silent no-op for Bank/Cheque
 * or a branch with no active cash account" convention as CashbookPoster.
 */
class FdCashbookPoster
{
    /** A Cash-method deposit is physical money in the cashier's hand the moment it's recorded — posted as a Cashbook "In" entry. */
    public static function postDepositReceived(FdDeposit $deposit): void
    {
        if ($deposit->payment_method !== 'Cash') {
            return;
        }
        $account = CashAccount::branchPrimary((int) $deposit->branch_id);
        if (!$account) {
            return;
        }

        DB::transaction(function () use ($account, $deposit) {
            $account = CashAccount::lockForUpdate()->find($account->id);
            $newBalance = bcadd((string) $account->current_balance, (string) $deposit->amount, 2);
            $account->update(['current_balance' => $newBalance]);

            CashbookEntry::create([
                'branch_id' => $account->branch_id,
                'cash_account_id' => $account->id,
                'entry_date' => $deposit->deposit_date,
                'direction' => 'In',
                'category' => 'Deposit',
                'amount' => $deposit->amount,
                'balance_after' => $newBalance,
                'source_type' => 'FdDeposit',
                'source_id' => $deposit->id,
                'description' => "FD deposit received - {$deposit->deposit_number}",
                'created_by' => $deposit->created_by,
            ]);
        });
    }

    /** Unwinds the Cashbook entry for a cash deposit being deleted (FdDepositController::destroy() only ever allows this while the deposit is still Available, i.e. before it's funded an FD) — deletes it and reverses the balance. No-op if the deposit never had one (Bank/Cheque). */
    public static function reverseDepositReceived(FdDeposit $deposit): void
    {
        $entry = CashbookEntry::where('source_type', 'FdDeposit')->where('source_id', $deposit->id)->first();
        if (!$entry) {
            return;
        }

        DB::transaction(function () use ($entry) {
            $account = CashAccount::lockForUpdate()->find($entry->cash_account_id);
            if ($account) {
                $account->update(['current_balance' => bcsub((string) $account->current_balance, (string) $entry->amount, 2)]);
            }
            $entry->delete();
        });
    }

    /** A Cash-method interest installment leaves the till the moment it's paid out. */
    public static function postInterestPaid(FdInterestPayment $payment): void
    {
        if ($payment->payment_method !== 'Cash') {
            return;
        }
        $fd = $payment->fixedDeposit;
        $account = CashAccount::branchPrimary((int) $fd->branch_id);
        if (!$account) {
            return;
        }

        DB::transaction(function () use ($account, $payment, $fd) {
            $account = CashAccount::lockForUpdate()->find($account->id);
            $newBalance = bcsub((string) $account->current_balance, (string) $payment->paid_amount, 2);
            $account->update(['current_balance' => $newBalance]);

            CashbookEntry::create([
                'branch_id' => $account->branch_id,
                'cash_account_id' => $account->id,
                'entry_date' => $payment->paid_at,
                'direction' => 'Out',
                'category' => 'Expense',
                'amount' => $payment->paid_amount,
                'balance_after' => $newBalance,
                'source_type' => 'FdInterestPayment',
                'source_id' => $payment->id,
                'description' => "FD interest paid - {$fd->fd_number} #{$payment->installment_no}",
                'created_by' => $payment->paid_by,
            ]);
        });
    }

    /** A Cash-method withdrawal (principal, plus any maturity-method interest) leaves the till when the FD closes. */
    public static function postWithdrawal(FdWithdrawal $withdrawal): void
    {
        if ($withdrawal->payment_method !== 'Cash') {
            return;
        }
        $fd = $withdrawal->fixedDeposit;
        $account = CashAccount::branchPrimary((int) $fd->branch_id);
        if (!$account) {
            return;
        }

        DB::transaction(function () use ($account, $withdrawal, $fd) {
            $account = CashAccount::lockForUpdate()->find($account->id);
            $newBalance = bcsub((string) $account->current_balance, (string) $withdrawal->total_amount, 2);
            $account->update(['current_balance' => $newBalance]);

            CashbookEntry::create([
                'branch_id' => $account->branch_id,
                'cash_account_id' => $account->id,
                'entry_date' => $withdrawal->closed_at,
                'direction' => 'Out',
                'category' => 'Other',
                'amount' => $withdrawal->total_amount,
                'balance_after' => $newBalance,
                'source_type' => 'FdWithdrawal',
                'source_id' => $withdrawal->id,
                'description' => "FD closed - {$fd->fd_number} ({$withdrawal->voucher_no})",
                'created_by' => $withdrawal->created_by,
            ]);
        });
    }

    /** Unwinds the Cashbook entry for a withdrawal being voided — reverses the till balance and deletes the entry. No-op if it never had one (Bank/Cheque). */
    public static function reverseWithdrawal(FdWithdrawal $withdrawal): void
    {
        $entry = CashbookEntry::where('source_type', 'FdWithdrawal')->where('source_id', $withdrawal->id)->first();
        if (!$entry) {
            return;
        }
        DB::transaction(function () use ($entry) {
            $account = CashAccount::lockForUpdate()->find($entry->cash_account_id);
            if ($account) {
                $account->update(['current_balance' => bcadd((string) $account->current_balance, (string) $entry->amount, 2)]);
            }
            $entry->delete();
        });
    }

    /** Unwinds the Cashbook entry for an interest payment being voided — reverses the till balance and deletes the entry. No-op if it never had one (Bank/Cheque). */
    public static function reverseInterestPaid(FdInterestPayment $payment): void
    {
        $entry = CashbookEntry::where('source_type', 'FdInterestPayment')->where('source_id', $payment->id)->first();
        if (!$entry) {
            return;
        }
        DB::transaction(function () use ($entry) {
            $account = CashAccount::lockForUpdate()->find($entry->cash_account_id);
            if ($account) {
                $account->update(['current_balance' => bcadd((string) $account->current_balance, (string) $entry->amount, 2)]);
            }
            $entry->delete();
        });
    }

    /** A Cash-method premature part-payment leaves the till for net_amount_paid (principal + interest paid, less any clawback) when it's recorded. */
    public static function postPartPayment(FdPartPayment $partPayment): void
    {
        if ($partPayment->payment_method !== 'Cash') {
            return;
        }
        $fd = $partPayment->fixedDeposit;
        $account = CashAccount::branchPrimary((int) $fd->branch_id);
        if (!$account) {
            return;
        }

        DB::transaction(function () use ($account, $partPayment, $fd) {
            $account = CashAccount::lockForUpdate()->find($account->id);
            $newBalance = bcsub((string) $account->current_balance, (string) $partPayment->net_amount_paid, 2);
            $account->update(['current_balance' => $newBalance]);

            CashbookEntry::create([
                'branch_id' => $account->branch_id,
                'cash_account_id' => $account->id,
                'entry_date' => $partPayment->paid_at,
                'direction' => 'Out',
                'category' => 'Other',
                'amount' => $partPayment->net_amount_paid,
                'balance_after' => $newBalance,
                'source_type' => 'FdPartPayment',
                'source_id' => $partPayment->id,
                'description' => "FD part payment - {$fd->fd_number} ({$partPayment->voucher_no})",
                'created_by' => $partPayment->created_by,
            ]);
        });
    }

    /** Unwinds the Cashbook entry for a part payment being voided — reverses the till balance and deletes the entry. No-op if it never had one (Bank/Cheque). */
    public static function reversePartPayment(FdPartPayment $partPayment): void
    {
        $entry = CashbookEntry::where('source_type', 'FdPartPayment')->where('source_id', $partPayment->id)->first();
        if (!$entry) {
            return;
        }
        DB::transaction(function () use ($entry) {
            $account = CashAccount::lockForUpdate()->find($entry->cash_account_id);
            if ($account) {
                $account->update(['current_balance' => bcadd((string) $account->current_balance, (string) $entry->amount, 2)]);
            }
            $entry->delete();
        });
    }
}
