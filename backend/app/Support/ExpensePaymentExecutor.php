<?php

namespace App\Support;

use App\Exceptions\FinancePostingException;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\FinanceTransaction;
use App\Models\PaymentVoucher;
use App\Services\NumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Pays an approved expense: moves the money through FinancePostingService
 * (Dr the category's expense account / Cr the till or bank it left from) and
 * records the PaymentVoucher that ties it together.
 *
 * Two entry points, mirroring the loan disbursement completers:
 *   - pay()           the direct path (Finance / admin) — moves money + voucher;
 *   - recordVoucher()  the request path — the money already moved inside
 *                      FinanceRequestService::execute(), this only writes the
 *                      voucher and flips the expense to Paid.
 */
class ExpensePaymentExecutor
{
    /**
     * @param array{payment_method:string, cash_account_id?:?int, bank_account_id?:?int, cheque_no?:?string, paid_at?:?string} $spec
     */
    public static function pay(Expense $expense, array $spec, int $userId): PaymentVoucher
    {
        if ($expense->status !== 'Approved') {
            throw new FinancePostingException('Only an approved expense can be paid.');
        }
        $category = $expense->category;
        if (!$category || !$category->chart_of_account_id) {
            throw new FinancePostingException("The expense category \"" . ($category->name ?? '?') . "\" has no linked chart-of-accounts entry yet — set one in Masters › Expense Categories first.");
        }

        $method = $spec['payment_method'];
        $isCash = $method === 'Cash';
        $amount = (string) $expense->amount;
        $entryDate = isset($spec['paid_at']) ? \Illuminate\Support\Carbon::parse($spec['paid_at'])->toDateString() : now()->toDateString();

        return DB::transaction(function () use ($expense, $category, $spec, $userId, $method, $isCash, $amount, $entryDate) {
            [$srcType, $srcId, $srcLeaf] = self::resolveSource($method, $spec['cash_account_id'] ?? null, $spec['bank_account_id'] ?? null);

            // The expense was recognised against its category account at approval
            // time (ExpenseController::postAccrual — Dr category / Cr 2300).
            // Payment relieves the 2300 payable, it does not hit the expense
            // account again. Backfill the accrual for a pre-accrual expense.
            \App\Http\Controllers\Api\ExpenseController::postAccrual($expense, $userId);

            $txn = FinancePostingService::execute([
                'txn_type' => 'EXPENSE_PAYMENT',
                'amount' => $amount,
                'entry_date' => $entryDate,
                'branch_id' => $expense->branch_id,
                'movements' => [['account_type' => $srcType, 'account_id' => $srcId, 'delta' => '-' . $amount]],
                'gl_lines' => [
                    ['account_id' => \App\Models\ChartOfAccount::system('2300')->id, 'debit' => $amount, 'credit' => 0, 'description' => $expense->description],
                    ['account_id' => $srcLeaf->id, 'debit' => 0, 'credit' => $amount],
                ],
                'gl_source_type' => 'Expense',
                'gl_source_id' => $expense->id,
                'gl_description' => "Expense payment {$expense->code}",
                'description' => "Expense payment {$expense->code}",
                'source_module' => 'Expense',
                'source_id' => $expense->id,
                'idempotency_key' => "expense-payment-{$expense->id}",
                'executed_by' => $userId,
            ]);

            $existing = PaymentVoucher::where('finance_transaction_id', $txn->id)->first();

            return $existing ?? self::recordVoucher($expense, $txn, $method, $spec['cheque_no'] ?? null, $userId);
        });
    }

    /** Writes the PaymentVoucher for an already-executed expense-payment transaction and flips the expense to Paid. Call inside a transaction. */
    public static function recordVoucher(Expense $expense, FinanceTransaction $txn, string $method, ?string $chequeNo, ?int $userId): PaymentVoucher
    {
        $code = NumberGenerator::next('payment_voucher', ['branch_code' => $expense->branch?->code ?? 'HO']);
        $voucher = PaymentVoucher::create([
            'code' => $code,
            'expense_id' => $expense->id,
            'vendor_id' => $expense->vendor_id,
            'amount' => $txn->amount,
            'payment_method' => $method,
            'paid_at' => $txn->executed_at ?? now(),
            'paid_by' => $userId,
            'cash_account_id' => $txn->source_account_type === 'cash' ? $txn->source_account_id : null,
            'bank_account_id' => $txn->source_account_type === 'bank' ? $txn->source_account_id : null,
            'cheque_no' => $chequeNo,
            'status' => 'Issued',
            'journal_entry_id' => $txn->journal_entry_id,
            'finance_transaction_id' => $txn->id,
        ]);
        NumberGenerator::attach($code, $voucher);

        $expense->update(['status' => 'Paid']);

        return $voucher;
    }

    /** @return array{0:string,1:int,2:ChartOfAccount} */
    private static function resolveSource(string $method, ?int $cashId, ?int $bankId): array
    {
        if ($method === 'Cash') {
            $cash = CashAccount::findOrFail($cashId);

            return ['cash', (int) $cash->id, ChartOfAccount::forCashTill($cash)];
        }
        $bank = BankAccount::findOrFail($bankId);

        return ['bank', (int) $bank->id, ChartOfAccount::forBankAccount($bank)];
    }
}
