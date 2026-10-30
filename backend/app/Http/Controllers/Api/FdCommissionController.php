<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FdCommission;
use App\Models\FinanceTransaction;
use App\Models\FixedDeposit;
use App\Support\FinancePostingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Commissions set by hand on a Fixed Deposit (or its renewal) and paid out manually afterwards,
 * to a staff member or an outsider. Pays through the same money writer as loan commissions
 * (Dr 5200 / Cr the paying till or bank).
 */
class FdCommissionController extends Controller
{
    private const PAYMENT_METHODS = ['Cash', 'Bank', 'Cheque'];

    private const RELATIONS = ['fixedDeposit:id,fd_number,customer_id,principal_amount,start_date,maturity_date,status,closed_at,term_months,renewed_from_id', 'fixedDeposit.customer:id,full_name,code', 'staff:id,staff_code,name'];

    public function index(Request $request)
    {
        $q = FdCommission::with(self::RELATIONS)->orderByDesc('id');
        if ($request->filled('status')) {
            $request->input('status') === 'Paid' ? $q->whereNotNull('paid_at') : $q->whereNull('paid_at');
        }
        if ($request->filled('recipient_type')) {
            $q->where('recipient_type', $request->input('recipient_type'));
        }
        if ($request->filled('staff_id')) {
            $q->where('staff_id', $request->input('staff_id'));
        }
        if ($request->filled('fixed_deposit_id')) {
            $q->where('fixed_deposit_id', $request->input('fixed_deposit_id'));
        }

        return $q->get();
    }

    /** Adds a commission to an existing FD that was created without one. */
    public function store(Request $request, FixedDeposit $fixedDeposit)
    {
        $data = $request->validate([
            'recipient_type' => 'required|in:staff,outsider',
            'staff_id' => 'required_if:recipient_type,staff|nullable|exists:staff,id',
            'recipient_name' => 'required_if:recipient_type,outsider|nullable|string|max:150',
            'recipient_phone' => 'nullable|string|max:32',
            'commission_type' => 'required|in:percentage,fixed',
            'commission_value' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
        ]);
        $staff = $data['recipient_type'] === 'staff' ? \App\Models\Staff::findOrFail($data['staff_id']) : null;

        $commission = $fixedDeposit->commissions()->create([
            'branch_id' => $fixedDeposit->branch_id,
            'recipient_type' => $data['recipient_type'],
            'staff_id' => $staff?->id,
            'recipient_name' => $staff?->name ?? $data['recipient_name'],
            'recipient_phone' => $staff?->phone ?? ($data['recipient_phone'] ?? null),
            'commission_type' => $data['commission_type'],
            'commission_value' => $data['commission_value'],
            'commission_amount' => FdCommission::amountFor($data['commission_type'], (float) $data['commission_value'], (float) $fixedDeposit->principal_amount),
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($commission->load(self::RELATIONS), 201);
    }

    /** Changes the recipient / rate of a commission that has not been paid yet. */
    public function update(Request $request, FdCommission $commission)
    {
        if ($commission->paid_at) {
            return response()->json(['message' => 'A paid commission cannot be edited. Void the payment first.'], 422);
        }
        $data = $request->validate([
            'recipient_type' => 'required|in:staff,outsider',
            'staff_id' => 'required_if:recipient_type,staff|nullable|exists:staff,id',
            'recipient_name' => 'required_if:recipient_type,outsider|nullable|string|max:150',
            'recipient_phone' => 'nullable|string|max:32',
            'commission_type' => 'required|in:percentage,fixed',
            'commission_value' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
        ]);
        $staff = $data['recipient_type'] === 'staff' ? \App\Models\Staff::findOrFail($data['staff_id']) : null;
        $commission->loadMissing('fixedDeposit');

        $commission->update([
            'recipient_type' => $data['recipient_type'],
            'staff_id' => $staff?->id,
            'recipient_name' => $staff?->name ?? $data['recipient_name'],
            'recipient_phone' => $staff?->phone ?? ($data['recipient_phone'] ?? null),
            'commission_type' => $data['commission_type'],
            'commission_value' => $data['commission_value'],
            'commission_amount' => FdCommission::amountFor($data['commission_type'], (float) $data['commission_value'], (float) $commission->fixedDeposit->principal_amount),
            'notes' => $data['notes'] ?? null,
        ]);

        return $commission->fresh()->load(self::RELATIONS);
    }

    public function destroy(FdCommission $commission)
    {
        if ($commission->paid_at) {
            return response()->json(['message' => 'A paid commission cannot be deleted. Void the payment first.'], 422);
        }
        $commission->delete();

        return response()->json(['ok' => true]);
    }

    public function pay(Request $request, FdCommission $commission)
    {
        if ($commission->paid_at) {
            return response()->json(['message' => 'This commission has already been paid.'], 422);
        }

        $data = $request->validate([
            'payment_date' => 'required|date',
            'payment_amount' => 'required|numeric|min:0.01',
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'cash_account_id' => 'required_if:payment_method,Cash|nullable|exists:cash_accounts,id',
            'bank_account_id' => 'required_if:payment_method,Bank,Cheque|nullable|exists:bank_accounts,id',
            'reference_no' => 'required_if:payment_method,Cheque|nullable|string|max:64',
            'notes' => 'nullable|string|max:255',
        ]);

        if (round((float) $data['payment_amount'], 2) > round((float) $commission->commission_amount, 2) + 0.005) {
            return response()->json(['message' => 'The payment cannot exceed the commission (' . number_format((float) $commission->commission_amount, 2) . ').'], 422);
        }
        $commission->loadMissing('fixedDeposit');
        $label = "{$commission->recipient_name} - {$commission->fixedDeposit?->fd_number}";

        try {
            DB::transaction(function () use ($commission, $data, $request, $label) {
                $amount = number_format((float) $data['payment_amount'], 2, '.', '');
                $isCash = $data['payment_method'] === 'Cash';

                if ($isCash) {
                    $physType = 'cash';
                    $physId = (int) CashAccount::findOrFail($data['cash_account_id'])->id;
                    $outAccount = ChartOfAccount::forCashTill(CashAccount::find($physId));
                } else {
                    $physType = 'bank';
                    $physId = (int) BankAccount::findOrFail($data['bank_account_id'])->id;
                    $outAccount = ChartOfAccount::forBankAccount(BankAccount::find($physId));
                }

                FinancePostingService::execute([
                    'txn_type' => $isCash ? 'CASH_OUT' : 'BANK_WITHDRAWAL',
                    'amount' => $amount,
                    'entry_date' => Carbon::parse($data['payment_date'])->toDateString(),
                    'branch_id' => $commission->branch_id,
                    'movements' => [['account_type' => $physType, 'account_id' => $physId, 'delta' => '-' . $amount]],
                    'gl_lines' => [
                        ['account_id' => ChartOfAccount::system('5200')->id, 'debit' => $amount, 'credit' => 0],
                        ['account_id' => $outAccount->id, 'debit' => 0, 'credit' => $amount],
                    ],
                    'gl_description' => "FD commission paid - {$label}",
                    'description' => "FD commission paid - {$label}",
                    'source_module' => 'FdCommission',
                    'source_id' => $commission->id,
                    // A void followed by a re-payment is a new attempt, so the key carries the attempt number.
                    'idempotency_key' => "fd-commission-payment-{$commission->id}-" . (FinanceTransaction::where('source_module', 'FdCommission')->where('source_id', $commission->id)->where('txn_type', '!=', 'REVERSAL')->count() + 1),
                    'executed_by' => $request->user()->id,
                ]);

                $commission->update([
                    'paid_at' => $data['payment_date'],
                    'payment_amount' => $amount,
                    'payment_method' => $data['payment_method'],
                    'cash_account_id' => $isCash ? $data['cash_account_id'] : null,
                    'bank_account_id' => $isCash ? null : $data['bank_account_id'],
                    'reference_no' => $data['reference_no'] ?? null,
                    'payment_notes' => $data['notes'] ?? null,
                    'paid_by' => $request->user()->id,
                ]);
            });
        } catch (\App\Exceptions\FinancePostingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $commission->fresh()->load(self::RELATIONS);
    }

    /** Undoes a mistaken payout: reverses the GL entry and till/bank movement, and puts the commission back to unpaid. */
    public function void(Request $request, FdCommission $commission)
    {
        if (!$commission->paid_at) {
            return response()->json(['message' => 'This commission has not been paid.'], 422);
        }

        DB::transaction(function () use ($commission, $request) {
            $txn = FinanceTransaction::where('source_module', 'FdCommission')
                ->where('source_id', $commission->id)->where('status', 'Completed')->latest('id')->first();
            if ($txn) {
                FinancePostingService::reverse($txn, "Voided FD commission payment - {$commission->recipient_name}", $request->user()->id);
            }
            $commission->update([
                'paid_at' => null,
                'payment_amount' => null,
                'payment_method' => null,
                'cash_account_id' => null,
                'bank_account_id' => null,
                'reference_no' => null,
                'payment_notes' => null,
                'paid_by' => null,
            ]);
        });

        return $commission->fresh()->load(self::RELATIONS);
    }
}
