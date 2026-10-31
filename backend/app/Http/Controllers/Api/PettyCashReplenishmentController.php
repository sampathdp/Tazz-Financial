<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\PettyCashFund;
use App\Models\PettyCashReplenishment;
use App\Services\NumberGenerator;
use App\Support\FinancePostingService;
use App\Support\LedgerPoster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PettyCashReplenishmentController extends Controller
{
    public function index(Request $request)
    {
        return PettyCashReplenishment::with([
            'fund:id,code,name,branch_id', 'fund.branch:id,name,code',
            'cashAccount:id,name', 'bankAccount:id,name', 'payer:id,name', 'voider:id,name',
        ])
            ->when($request->query('petty_cash_fund_id'), fn ($q, $f) => $q->where('petty_cash_fund_id', $f))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Tops the float back up from a till or bank account: Dr the fund's own
     * GL leaf, Cr the source account — the mirror image of a
     * PettyCashVoucher's posting. See ExpenseController::pay() for the same
     * lock-then-move-balance-then-post shape.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'petty_cash_fund_id' => 'required|exists:petty_cash_funds,id',
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'funding_method' => ['required', Rule::in(['Cash', 'Bank'])],
            'cash_account_id' => 'required_if:funding_method,Cash|nullable|exists:cash_accounts,id',
            'bank_account_id' => 'required_if:funding_method,Bank|nullable|exists:bank_accounts,id',
            'paid_at' => 'nullable|date',
        ]);

        $fund = PettyCashFund::findOrFail($data['petty_cash_fund_id']);
        if ($fund->status !== 'Active') {
            return response()->json(['message' => 'This float is inactive and cannot be replenished.'], 422);
        }
        if (bccomp(bcadd((string) $fund->current_balance, (string) $data['amount'], 2), (string) $fund->float_limit, 2) > 0) {
            return response()->json(['message' => "This top-up would take the float above its limit of {$fund->float_limit}."], 422);
        }

        $replenishment = DB::transaction(function () use ($fund, $data, $request) {
            $paidAt = $data['paid_at'] ?? now();
            $isCash = $data['funding_method'] === 'Cash';

            $source = $isCash
                ? CashAccount::findOrFail($data['cash_account_id'])
                : BankAccount::findOrFail($data['bank_account_id']);
            $outAccount = $isCash ? ChartOfAccount::forCashTill($source) : ChartOfAccount::forBankAccount($source);

            $fundLocked = PettyCashFund::lockForUpdate()->findOrFail($fund->id);
            if ($fundLocked->status !== 'Active' || bccomp(bcadd((string) $fundLocked->current_balance, (string) $data['amount'], 2), (string) $fundLocked->float_limit, 2) > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => "This top-up would take the float above its limit of {$fundLocked->float_limit}."]);
            }
            $inAccount = ChartOfAccount::forPettyCashFund($fundLocked);

            $code = NumberGenerator::next('petty_cash_replenishment', ['branch_code' => $fundLocked->branch->code]);
            $replenishment = PettyCashReplenishment::create([
                'code' => $code,
                'petty_cash_fund_id' => $fundLocked->id,
                'amount' => $data['amount'],
                'funding_method' => $data['funding_method'],
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'paid_at' => $paidAt,
                'paid_by' => $request->user()->id,
                'status' => 'Issued',
            ]);
            NumberGenerator::attach($code, $replenishment);

            // The money leaves the till / bank through the one money writer: funds check, finance
            // transaction row, cashbook mirror and (when required) the cash-session gate, atomically
            // with the GL entry - so a top-up shows in Cash Transactions like every other payout.
            $txn = FinancePostingService::execute([
                'txn_type' => $isCash ? 'CASH_OUT' : 'BANK_WITHDRAWAL',
                'amount' => (string) $data['amount'],
                'entry_date' => Carbon::parse($paidAt)->toDateString(),
                'branch_id' => $fundLocked->branch_id,
                'movements' => [['account_type' => $isCash ? 'cash' : 'bank', 'account_id' => (int) $source->id, 'delta' => '-' . $data['amount']]],
                'gl_lines' => [
                    ['account_id' => $inAccount->id, 'debit' => $data['amount'], 'credit' => 0],
                    ['account_id' => $outAccount->id, 'debit' => 0, 'credit' => $data['amount']],
                ],
                'gl_source_type' => 'PettyCashReplenishment',
                'gl_source_id' => $replenishment->id,
                'gl_description' => "Petty cash top-up {$fundLocked->code}",
                'description' => "Petty cash top-up {$fundLocked->code} ({$replenishment->code})",
                'source_module' => 'PettyCashReplenishment',
                'source_id' => $replenishment->id,
                'idempotency_key' => "petty-cash-topup-{$replenishment->id}",
                'executed_by' => $request->user()->id,
            ]);
            $fundLocked->update(['current_balance' => bcadd((string) $fundLocked->current_balance, (string) $data['amount'], 2)]);
            $replenishment->update(['journal_entry_id' => $txn->journal_entry_id]);

            return $replenishment;
        });

        return response()->json($replenishment->load('journalEntry', 'fund:id,code,name'), 201);
    }

    /**
     * Voids a top-up: reverses its GL posting and hands the money back to
     * the source account. Blocked once the float has spent below what this
     * top-up added, same "can't unwind what's already gone" guard as
     * PaymentVoucher's cash movement.
     */
    public function destroy(Request $request, PettyCashReplenishment $pettyCashReplenishment)
    {
        if ($pettyCashReplenishment->status === 'Voided') {
            return response()->json(['message' => 'This top-up is already voided.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);

        $fund = PettyCashFund::findOrFail($pettyCashReplenishment->petty_cash_fund_id);
        if (bccomp((string) $fund->current_balance, (string) $pettyCashReplenishment->amount, 2) < 0) {
            return response()->json(['message' => 'This top-up has already been spent from the float and cannot be voided.'], 422);
        }

        DB::transaction(function () use ($pettyCashReplenishment, $fund, $data, $request) {
            $fundLocked = PettyCashFund::lockForUpdate()->findOrFail($fund->id);
            if (PettyCashReplenishment::lockForUpdate()->findOrFail($pettyCashReplenishment->id)->status === 'Voided') {
                throw \Illuminate\Validation\ValidationException::withMessages(['top_up' => 'This top-up is already voided.']);
            }
            if (bccomp((string) $fundLocked->current_balance, (string) $pettyCashReplenishment->amount, 2) < 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['top_up' => 'This top-up has already been spent from the float and cannot be voided.']);
            }
            $fundLocked->update(['current_balance' => bcsub((string) $fundLocked->current_balance, (string) $pettyCashReplenishment->amount, 2)]);

            $txn = FinanceTransaction::where('source_module', 'PettyCashReplenishment')
                ->where('source_id', $pettyCashReplenishment->id)->where('txn_type', '!=', 'REVERSAL')->where('status', 'Completed')->latest('id')->first();
            if ($txn) {
                // Posted through the money writer: restores the source balance, mirrors the GL and leaves a REVERSAL row.
                FinancePostingService::reverse($txn, "Voided: {$pettyCashReplenishment->code}", $request->user()->id);
            } else {
                // A top-up made before it went through the money writer: walk the balance back by hand.
                if ($pettyCashReplenishment->cash_account_id) {
                    $source = CashAccount::lockForUpdate()->findOrFail($pettyCashReplenishment->cash_account_id);
                    $source->update(['current_balance' => bcadd((string) $source->current_balance, (string) $pettyCashReplenishment->amount, 2)]);
                } elseif ($pettyCashReplenishment->bank_account_id) {
                    $source = BankAccount::lockForUpdate()->findOrFail($pettyCashReplenishment->bank_account_id);
                    $source->update(['current_balance' => bcadd((string) $source->current_balance, (string) $pettyCashReplenishment->amount, 2)]);
                }
                if ($pettyCashReplenishment->journal_entry_id) {
                    LedgerPoster::reverse($pettyCashReplenishment->journalEntry, "Voided: {$pettyCashReplenishment->code}", $request->user()->id);
                }
            }

            $pettyCashReplenishment->update([
                'status' => 'Voided',
                'void_reason' => $data['void_reason'] ?? null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);
        });

        return $pettyCashReplenishment->fresh()->load('voider:id,name');
    }
}
