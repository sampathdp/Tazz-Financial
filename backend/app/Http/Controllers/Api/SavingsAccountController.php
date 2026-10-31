<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalType;
use App\Models\Branch;
use App\Models\FinanceSetting;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Services\NumberGenerator;
use App\Support\CurrentBranch;
use App\Support\SavingsAccountService;
use App\Support\SavingsLedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SavingsAccountController extends Controller
{
    private const PAYMENT_METHODS = ['Cash', 'Bank', 'Cheque'];

    private const SUMMARY_RELATIONS = ['customer:id,full_name,code,nic_no,phone', 'branch:id,name,code', 'savingsProduct:id,name,code'];

    private const DETAIL_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone,email,address',
        'branch:id,name,code',
        'savingsProduct',
        'creator:id,name',
    ];

    public function index(Request $request)
    {
        $q = CurrentBranch::applyScope(SavingsAccount::with(self::SUMMARY_RELATIONS))->orderByDesc('id');

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($customerId = $request->query('customerId')) {
            $q->where('customer_id', $customerId);
        }
        if ($productId = $request->query('productId')) {
            $q->where('savings_product_id', $productId);
        }
        if ($request->boolean('dormantDue')) {
            $q->where('status', 'Active');
        }

        return $q->get();
    }

    /**
     * Admin-only edit. Notes are always editable on an open account; interest/fee terms only
     * before any movement beyond the opening deposit (and its fee).
     */
    public function adminUpdate(Request $request, SavingsAccount $savingsAccount)
    {
        if ($savingsAccount->status === 'Closed') {
            return response()->json(['message' => 'A closed account cannot be edited.'], 422);
        }
        $data = $request->validate([
            'notes' => 'nullable|string',
            'interest_rate_percent' => 'nullable|numeric|min:0|max:100',
            'interest_calc_method' => 'nullable|string|max:40',
            'interest_posting_frequency' => 'nullable|string|max:40',
            'min_balance' => 'nullable|numeric|min:0',
            'withdrawal_fee' => 'nullable|numeric|min:0',
        ]);

        $txns = $savingsAccount->transactions();
        $moved = (clone $txns)->whereNotIn('type', ['Deposit', 'Fee'])->exists()
            || (clone $txns)->where('type', 'Deposit')->count() > 1
            || (clone $txns)->where('type', 'Fee')->count() > 1
            || (float) $savingsAccount->interest_accrued > 0;
        $terms = array_diff_key($data, ['notes' => 1]);
        if ($moved && array_filter($terms, fn ($v) => $v !== null)) {
            return response()->json(['message' => 'This account already has transactions; only the notes can be edited.'], 422);
        }

        $savingsAccount->update(array_filter($data, fn ($v, $k) => $k === 'notes' || $v !== null, ARRAY_FILTER_USE_BOTH));

        return $this->show($savingsAccount->fresh());
    }

    public function show(SavingsAccount $savingsAccount)
    {
        return $savingsAccount->load(self::DETAIL_RELATIONS);
    }

    /** Ledger / pass-book — every posted movement, newest first. */
    public function statement(Request $request, SavingsAccount $savingsAccount)
    {
        $q = $savingsAccount->transactions()->with(['bank:id,name', 'creator:id,name', 'collectionSheet:id,sheet_number', 'interestRun:id,run_number']);
        if ($from = $request->query('from')) {
            $q->whereDate('txn_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('txn_date', '<=', $to);
        }
        if ($request->boolean('postedOnly')) {
            $q->where('status', 'Posted');
        }

        return [
            'account' => $savingsAccount->load(self::DETAIL_RELATIONS),
            'transactions' => $q->get(),
            'summary' => $this->lifetimeSummary($savingsAccount),
        ];
    }

    /** Lifetime totals over every posted movement (ignores the statement date filter). */
    private function lifetimeSummary(SavingsAccount $account): array
    {
        $posted = $account->transactions()->where('status', 'Posted');
        $byType = (clone $posted)->selectRaw('type, direction, COUNT(*) as n, COALESCE(SUM(amount), 0) as total')
            ->groupBy('type', 'direction')->get();

        $sum = function (string $type, ?string $direction = null) use ($byType) {
            return round((float) $byType->filter(function ($r) use ($type, $direction) {
                return $r->type === $type && ($direction === null || $r->direction === $direction);
            })->sum('total'), 2);
        };

        $first = (clone $posted)->min('txn_date');
        $last = (clone $posted)->max('txn_date');

        return [
            'totalDeposits' => $sum('Deposit'),
            'totalWithdrawals' => $sum('Withdrawal'),
            'totalInterest' => $sum('InterestPosting'),
            'totalFees' => $sum('Fee'),
            'transactionCount' => (int) $byType->sum('n'),
            'collectionDeposits' => (int) (clone $posted)->whereNotNull('collection_sheet_id')->count(),
            'firstTxnDate' => $first ? substr((string) $first, 0, 10) : null,
            'lastTxnDate' => $last ? substr((string) $last, 0, 10) : null,
        ];
    }

    public function nextNumber(Request $request)
    {
        $data = $request->validate(['branch_id' => 'required|exists:branches,id', 'savings_product_id' => 'nullable|exists:savings_products,id']);
        $branch = Branch::find($data['branch_id']);
        $product = isset($data['savings_product_id']) ? SavingsProduct::find($data['savings_product_id']) : null;
        $code = NumberGenerator::peek('savings_account', [
            'branch_id' => $branch->id,
            'branch_code' => $branch->code,
            'funding_product_code' => $product->code ?? null,
        ], $product->reference_format ?? null);

        return response()->json(['code' => $code]);
    }

    /** Opens a new account, records the opening deposit, and charges the product's ledger fee (if any). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'required|exists:customers,id',
            'savings_product_id' => 'required|exists:savings_products,id',
            'open_date' => 'required|date',
            'opening_deposit' => 'required|numeric|min:0',
            'payment_method' => ['required_with:opening_deposit', Rule::in(self::PAYMENT_METHODS)],
            'reference_no' => 'nullable|string|max:64',
            'bank_id' => 'nullable|exists:banks,id|required_unless:payment_method,Cash',
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'notes' => 'nullable|string',
        ]);

        $product = SavingsProduct::findOrFail($data['savings_product_id']);
        if ($product->status !== 'Active') {
            throw ValidationException::withMessages(['savings_product_id' => 'This savings product is inactive.']);
        }
        $opening = round((float) $data['opening_deposit'], 2);
        if ($opening < (float) $product->min_opening_deposit) {
            throw ValidationException::withMessages(['opening_deposit' => "The opening deposit must be at least {$product->min_opening_deposit} for {$product->name}."]);
        }

        $account = DB::transaction(function () use ($data, $product, $opening, $request) {
            $branch = Branch::find($data['branch_id']);
            $number = NumberGenerator::next('savings_account', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'funding_product_code' => $product->code,
                'user_id' => $request->user()->id,
            ], $product->reference_format);

            $account = SavingsAccount::create([
                'account_number' => $number,
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'savings_product_id' => $product->id,
                'interest_rate_percent' => $product->interest_rate_percent,
                'interest_calc_method' => $product->interest_calc_method,
                'interest_posting_frequency' => $product->interest_posting_frequency,
                'min_balance' => $product->min_balance,
                'withdrawal_fee' => $product->withdrawal_fee,
                'open_date' => $data['open_date'],
                'status' => 'Active',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($number, $account);
            $account->setRelation('savingsProduct', $product);

            if ($opening > 0) {
                $txn = SavingsAccountService::record($account, [
                    'type' => 'Deposit', 'direction' => 'In', 'amount' => $opening, 'txn_date' => $data['open_date'],
                    'payment_method' => $data['payment_method'], 'bank_id' => $data['bank_id'] ?? null, 'bank_account_id' => $data['bank_account_id'] ?? null,
                    'reference_no' => $data['reference_no'] ?? null, 'notes' => 'Opening deposit',
                    'created_by' => $request->user()->id,
                ]);
                $txn->setRelation('savingsAccount', $account);
                SavingsLedgerPoster::postDeposit($txn);
            }

            if ((float) $product->ledger_fee > 0) {
                $fee = SavingsAccountService::record($account, [
                    'type' => 'Fee', 'direction' => 'Out', 'amount' => round((float) $product->ledger_fee, 2), 'txn_date' => $data['open_date'],
                    'notes' => 'Account ledger fee', 'created_by' => $request->user()->id,
                ]);
                $fee->setRelation('savingsAccount', $account);
                SavingsLedgerPoster::postFee($fee);
            }

            return $account;
        });

        return response()->json($account->fresh()->load(self::DETAIL_RELATIONS), 201);
    }

    public function deposit(Request $request, SavingsAccount $savingsAccount)
    {
        $this->assertOperable($savingsAccount);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'txn_date' => 'required|date',
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'reference_no' => 'nullable|string|max:64',
            'bank_id' => 'nullable|exists:banks,id|required_unless:payment_method,Cash',
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'notes' => 'nullable|string',
        ]);

        $txn = DB::transaction(function () use ($savingsAccount, $data, $request) {
            $savingsAccount->loadMissing('savingsProduct', 'branch');
            $txn = SavingsAccountService::record($savingsAccount, [
                'type' => 'Deposit', 'direction' => 'In', 'amount' => round((float) $data['amount'], 2), 'txn_date' => $data['txn_date'],
                'payment_method' => $data['payment_method'], 'bank_id' => $data['bank_id'] ?? null, 'bank_account_id' => $data['bank_account_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null, 'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $txn->setRelation('savingsAccount', $savingsAccount);
            SavingsLedgerPoster::postDeposit($txn);

            return $txn;
        });

        return response()->json(['transaction' => $txn, 'account' => $savingsAccount->fresh()->load(self::DETAIL_RELATIONS)], 201);
    }

    public function withdraw(Request $request, SavingsAccount $savingsAccount)
    {
        $this->assertOperable($savingsAccount);
        $savingsAccount->loadMissing('savingsProduct', 'branch');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'txn_date' => 'required|date',
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id', 'required_if:payment_method,Bank,Cheque'],
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
        ]);

        $amount = round((float) $data['amount'], 2);
        $fee = round((float) $savingsAccount->withdrawal_fee, 2);
        $available = (float) $savingsAccount->current_balance - (float) $savingsAccount->min_balance;
        if ($amount + $fee > $available + 1e-6) {
            throw ValidationException::withMessages([
                'amount' => 'This withdrawal (plus any fee) would take the account below its minimum balance of ' . number_format((float) $savingsAccount->min_balance, 2) . '.',
            ]);
        }

        $threshold = (float) FinanceSetting::get('savings_large_withdrawal_threshold', '0');
        if ($threshold > 0 && $amount >= $threshold && !ApprovalType::isAuthorized($request->user(), 'savings_withdrawal')) {
            return response()->json(['message' => "A withdrawal of {$amount} needs approval from an authorised approver (savings_withdrawal)."], 422);
        }

        $result = DB::transaction(function () use ($savingsAccount, $data, $amount, $fee, $request) {
            // Re-check the minimum balance on the locked row: the guard above used a stale balance, so two
            // simultaneous withdrawals could both pass it and overdraw the account.
            $locked = SavingsAccount::lockForUpdate()->findOrFail($savingsAccount->id);
            if ($amount + $fee > (float) $locked->current_balance - (float) $locked->min_balance + 1e-6) {
                throw ValidationException::withMessages([
                    'amount' => 'This withdrawal (plus any fee) would take the account below its minimum balance of ' . number_format((float) $locked->min_balance, 2) . '.',
                ]);
            }
            $txn = SavingsAccountService::record($savingsAccount, [
                'type' => 'Withdrawal', 'direction' => 'Out', 'amount' => $amount, 'txn_date' => $data['txn_date'],
                'payment_method' => $data['payment_method'], 'bank_account_id' => $data['bank_account_id'] ?? null, 'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id,
            ]);
            $txn->setRelation('savingsAccount', $savingsAccount);
            SavingsLedgerPoster::postWithdrawal($txn);

            $feeTxn = null;
            if ($fee > 0) {
                $feeTxn = SavingsAccountService::record($savingsAccount, [
                    'type' => 'Fee', 'direction' => 'Out', 'amount' => $fee, 'txn_date' => $data['txn_date'],
                    'notes' => 'Withdrawal fee', 'created_by' => $request->user()->id,
                ]);
                $feeTxn->setRelation('savingsAccount', $savingsAccount);
                SavingsLedgerPoster::postFee($feeTxn);
            }

            return ['transaction' => $txn, 'fee' => $feeTxn];
        });

        return response()->json($result + ['account' => $savingsAccount->fresh()->load(self::DETAIL_RELATIONS)], 201);
    }

    /** Pays out the whole balance and closes the account (needs savings_closure authorisation). */
    public function close(Request $request, SavingsAccount $savingsAccount)
    {
        if ($savingsAccount->status === 'Closed') {
            return response()->json(['message' => 'This account is already closed.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'savings_closure')) {
            return response()->json(['message' => 'Closing a savings account needs approval from an authorised approver (savings_closure).'], 403);
        }
        $data = $request->validate([
            'txn_date' => 'required|date',
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id', 'required_if:payment_method,Bank,Cheque'],
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
        ]);

        $savingsAccount->loadMissing('savingsProduct', 'branch');
        $balance = round((float) $savingsAccount->current_balance, 2);
        if ($balance < 0) {
            return response()->json(['message' => 'This account has a negative balance and must be corrected before it can be closed.'], 422);
        }

        $payout = DB::transaction(function () use ($savingsAccount, $data, $balance, $request) {
            $payout = null;
            if ($balance > 0) {
                $payout = SavingsAccountService::record($savingsAccount, [
                    'type' => 'Withdrawal', 'direction' => 'Out', 'amount' => $balance, 'txn_date' => $data['txn_date'],
                    'payment_method' => $data['payment_method'], 'bank_account_id' => $data['bank_account_id'] ?? null, 'reference_no' => $data['reference_no'] ?? null,
                    'notes' => 'Closure payout', 'created_by' => $request->user()->id,
                ]);
                $payout->setRelation('savingsAccount', $savingsAccount);
                SavingsLedgerPoster::postWithdrawal($payout);
            }
            $savingsAccount->update(['status' => 'Closed', 'closed_on' => $data['txn_date'], 'notes' => $data['notes'] ?? $savingsAccount->notes]);

            return $payout;
        });

        return response()->json(['payout' => $payout, 'account' => $savingsAccount->fresh()->load(self::DETAIL_RELATIONS)], 201);
    }

    public function setStatus(Request $request, SavingsAccount $savingsAccount)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['Active', 'Dormant', 'Frozen'])]]);
        if ($savingsAccount->status === 'Closed') {
            return response()->json(['message' => 'A closed account cannot change status.'], 422);
        }
        $savingsAccount->update(['status' => $data['status']]);

        return $savingsAccount->fresh()->load(self::DETAIL_RELATIONS);
    }

    /**
     * Voids the most recent posted transaction on an account — reverses its GL
     * leg and walks the balance back. Only the latest is voidable so the
     * running_balance snapshots stay consistent (same rule as an FD part
     * payment). Needs savings_txn_void authorisation.
     */
    public function voidTransaction(Request $request, SavingsTransaction $savingsTransaction)
    {
        if (!ApprovalType::isAuthorized($request->user(), 'savings_txn_void')) {
            return response()->json(['message' => 'Voiding a savings transaction needs approval from an authorised approver (savings_txn_void).'], 403);
        }
        if ($savingsTransaction->status !== 'Posted') {
            return response()->json(['message' => 'This transaction is not in a voidable state.'], 422);
        }
        if (in_array($savingsTransaction->type, ['InterestPosting'], true)) {
            return response()->json(['message' => 'Void the interest run instead of an individual interest posting.'], 422);
        }
        $account = $savingsTransaction->savingsAccount;
        $latest = $account->transactions()->where('status', 'Posted')->orderByDesc('id')->first();
        if (!$latest || $latest->id !== $savingsTransaction->id) {
            return response()->json(['message' => 'Only the most recent transaction on an account can be voided — void the later ones first.'], 422);
        }

        DB::transaction(function () use ($savingsTransaction, $account, $request) {
            $account->loadMissing('savingsProduct', 'branch');
            SavingsLedgerPoster::reverseTransaction($savingsTransaction, $request->user()->id);

            // Balance walk-back: a reversing Adjustment of the opposite
            // direction (kept Posted — it is a real, auditable correction; the
            // originating transaction flips to Voided).
            SavingsAccountService::record($account, [
                'type' => 'Adjustment',
                'direction' => $savingsTransaction->direction === 'In' ? 'Out' : 'In',
                'amount' => $savingsTransaction->amount,
                'txn_date' => now()->format('Y-m-d'),
                'notes' => "Void of {$savingsTransaction->txn_number}",
                'void_of_id' => $savingsTransaction->id,
                'created_by' => $request->user()->id,
            ]);
            $savingsTransaction->update(['status' => 'Voided']);
        });

        return $account->fresh()->load(self::DETAIL_RELATIONS);
    }

    private function assertOperable(SavingsAccount $account): void
    {
        if (in_array($account->status, ['Frozen', 'Closed'], true)) {
            throw ValidationException::withMessages(['status' => "This account is {$account->status} — no deposits or withdrawals are allowed."]);
        }
    }
}
