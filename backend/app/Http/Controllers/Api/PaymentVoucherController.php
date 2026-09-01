<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\PaymentVoucher;
use App\Support\FinancePostingService;
use App\Support\LedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentVoucherController extends Controller
{
    public function index(Request $request)
    {
        return PaymentVoucher::with([
            'expense:id,code,branch_id,description', 'expense.branch:id,name,code',
            'vendor:id,name', 'payer:id,name', 'cashAccount:id,name', 'bankAccount:id,name', 'voider:id,name',
        ])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('branch_id'), fn ($q, $b) => $q->whereHas('expense', fn ($eq) => $eq->where('branch_id', $b)))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Voids an issued voucher: reverses the GL posting it made, restores the
     * balance on whichever till/bank account the money left, and hands the
     * source Expense back to Approved so it can be paid again — mirroring
     * LoanPaymentController::destroy()'s "voided, not deleted" convention.
     */
    public function destroy(Request $request, PaymentVoucher $paymentVoucher)
    {
        if ($paymentVoucher->status === 'Voided') {
            return response()->json(['message' => 'This voucher is already voided.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($paymentVoucher, $data, $request) {
            if ($paymentVoucher->finance_transaction_id && $paymentVoucher->financeTransaction?->status === 'Completed') {
                // Since Phase 4 the money moved through FinancePostingService —
                // reverse() restores the balance AND mirrors the GL, and leaves
                // a linked REVERSAL row.
                FinancePostingService::reverse($paymentVoucher->financeTransaction, "Voided: {$paymentVoucher->code}", $request->user()->id);
            } else {
                // Legacy voucher (pre-Phase-4): unwind the balance + GL by hand.
                if ($paymentVoucher->cash_account_id) {
                    $till = CashAccount::lockForUpdate()->findOrFail($paymentVoucher->cash_account_id);
                    $till->update(['current_balance' => bcadd((string) $till->current_balance, (string) $paymentVoucher->amount, 2)]);
                } elseif ($paymentVoucher->bank_account_id) {
                    $bank = BankAccount::lockForUpdate()->findOrFail($paymentVoucher->bank_account_id);
                    $bank->update(['current_balance' => bcadd((string) $bank->current_balance, (string) $paymentVoucher->amount, 2)]);
                }
                if ($paymentVoucher->journal_entry_id) {
                    LedgerPoster::reverse($paymentVoucher->journalEntry, "Voided: {$paymentVoucher->code}", $request->user()->id);
                }
            }

            if ($paymentVoucher->expense_id) {
                Expense::where('id', $paymentVoucher->expense_id)->where('status', 'Paid')->update(['status' => 'Approved']);
            }

            $paymentVoucher->update([
                'status' => 'Voided',
                'void_reason' => $data['void_reason'] ?? null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);
        });

        return $paymentVoucher->fresh()->load('voider:id,name');
    }
}
