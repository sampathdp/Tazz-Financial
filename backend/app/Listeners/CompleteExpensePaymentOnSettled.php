<?php

namespace App\Listeners;

use App\Events\FinanceRequestSettled;
use App\Models\Expense;
use App\Support\ExpensePaymentExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When an ExpensePayment finance request is paid (Finance released the
 * money), record the PaymentVoucher and flip the expense to Paid. The money
 * and the GL entry already moved inside FinanceRequestService::execute();
 * this only does the voucher / status book-keeping.
 *
 * Rejected / cancelled requests are a no-op — the expense stays Approved and
 * can be re-requested (or paid directly).
 */
class CompleteExpensePaymentOnSettled
{
    public function handle(FinanceRequestSettled $event): void
    {
        $request = $event->request;
        if ($request->request_type !== 'ExpensePayment' || $request->source_module !== 'Expense' || $event->outcome !== 'paid') {
            return;
        }

        $expense = Expense::find($request->source_id);
        $txn = $request->financeTransaction;
        if (!$expense || !$txn || $expense->status !== 'Approved') {
            return;
        }

        $meta = $request->meta ?? [];
        $method = $meta['payment_method'] ?? ($txn->source_account_type === 'cash' ? 'Cash' : 'Bank');

        try {
            DB::transaction(function () use ($expense, $txn, $method, $meta, $request) {
                // Backfill the Dr category / Cr 2300 accrual for an expense that
                // was approved before it started being posted automatically —
                // the payment above already relieved 2300.
                \App\Http\Controllers\Api\ExpenseController::postAccrual($expense, $request->executed_by);
                ExpensePaymentExecutor::recordVoucher($expense, $txn, $method, $meta['cheque_no'] ?? null, $request->executed_by);
                $request->update(['status' => 'Completed', 'closed_at' => now()]);
            });
        } catch (\Throwable $e) {
            Log::channel('errors')->error('Expense payment completion failed after payment', [
                'finance_request_id' => $request->id,
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
