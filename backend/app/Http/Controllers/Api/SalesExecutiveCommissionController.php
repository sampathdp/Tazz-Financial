<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\SalesExecutiveCommission;
use App\Support\LedgerPoster;
use App\Support\LoanSummary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesExecutiveCommissionController extends Controller
{
    private const PAYMENT_METHODS = ['Cash', 'Bank', 'Cheque'];

    private const RELATIONS = [
        'facility:id,code,customer_id,principal_amount,status',
        'facility.customer:id,full_name',
        'facility.schedules:id,credit_facility_id,installment_no,status,due_date',
        'facility.schedules.allocations:id,facility_schedule_id,interest_applied',
        'facility.schedules.allocations.payment:id,status,approval_status',
        'loanType:id,name',
        'salesExecutive:id,name',
        'paidBy:id,name',
    ];

    public function index(Request $request)
    {
        $query = SalesExecutiveCommission::with(self::RELATIONS)->orderBy('credit_facility_id')->orderBy('cycle_no');
        if ($request->filled('sales_executive_id')) {
            $query->where('sales_executive_id', $request->input('sales_executive_id'));
        }
        if ($request->filled('loan_type_id')) {
            $query->where('loan_type_id', $request->input('loan_type_id'));
        }

        $rows = $query->get()->map(fn ($c) => self::present($c));

        if ($request->filled('status')) {
            $rows = $rows->where('commission_status', $request->input('status'))->values();
        }

        return $rows;
    }

    public function pay(Request $request, SalesExecutiveCommission $commission)
    {
        if ($commission->paid_at) {
            return response()->json(['message' => 'This commission has already been paid.'], 422);
        }
        $commission->loadMissing('facility.schedules.allocations.payment');
        if (self::paidInstallments($commission) < self::eligibleAt($commission)) {
            return response()->json(['message' => 'This commission is not yet eligible for payment.'], 422);
        }

        $data = $request->validate([
            'payment_date' => 'required|date',
            'payment_amount' => 'required|numeric|min:0',
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'cash_account_id' => 'required_if:payment_method,Cash|nullable|exists:cash_accounts,id',
            'bank_account_id' => 'required_if:payment_method,Bank,Cheque|nullable|exists:bank_accounts,id',
            'reference_no' => 'required_if:payment_method,Cheque|nullable|string|max:64',
            'notes' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($commission, $data, $request) {
            $amount = (float) $data['payment_amount'];
            $isCash = $data['payment_method'] === 'Cash';

            if ($isCash) {
                $till = CashAccount::lockForUpdate()->findOrFail($data['cash_account_id']);
                $outAccount = ChartOfAccount::forCashTill($till);
                $till->update(['current_balance' => bcsub((string) $till->current_balance, (string) $amount, 2)]);
            } else {
                $bank = BankAccount::lockForUpdate()->findOrFail($data['bank_account_id']);
                $outAccount = ChartOfAccount::forBankAccount($bank);
                $bank->update(['current_balance' => bcsub((string) $bank->current_balance, (string) $amount, 2)]);
            }

            $commission->update([
                // Freeze the live-computed (interest-based) amount at the moment of
                // payment, so interest collected afterward never retroactively
                // changes this historical record — see self::commissionAmount().
                'commission_amount' => self::commissionAmount($commission),
                'paid_at' => $data['payment_date'],
                'payment_amount' => $amount,
                'payment_method' => $data['payment_method'],
                'cash_account_id' => $isCash ? $data['cash_account_id'] : null,
                'bank_account_id' => $isCash ? null : $data['bank_account_id'],
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_by' => $request->user()->id,
            ]);

            LedgerPoster::post([
                ['account_id' => ChartOfAccount::system('5200')->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $outAccount->id, 'debit' => 0, 'credit' => $amount],
            ], Carbon::parse($data['payment_date'])->toDateString(), [
                'source_type' => 'SalesExecutiveCommission',
                'source_id' => $commission->id,
                'description' => "Commission paid - {$commission->salesExecutive?->name} - {$commission->facility?->code}",
                'branch_id' => $commission->facility?->branch_id,
                'posted_by' => $request->user()->id,
            ]);

            self::maybeStartNextCycle($commission);
        });

        return self::present($commission->load(self::RELATIONS));
    }

    /**
     * Undoes a mistaken payout — reverses the GL entry, restores the till/
     * bank balance pay() moved, puts the commission back to unpaid, and
     * (for a percentage-type cycle) removes the next cycle pay() auto-
     * started via maybeStartNextCycle(), provided that next cycle is still
     * itself untouched. Admin-only, same gate as pay().
     */
    public function void(Request $request, SalesExecutiveCommission $commission)
    {
        if (!$commission->paid_at) {
            return response()->json(['message' => 'This commission has not been paid.'], 422);
        }
        $commission->loadMissing('facility');
        $amount = (float) $commission->payment_amount;

        DB::transaction(function () use ($commission, $amount, $request) {
            if ($commission->payment_method === 'Cash' && $commission->cash_account_id) {
                $till = CashAccount::lockForUpdate()->find($commission->cash_account_id);
                $till?->update(['current_balance' => bcadd((string) $till->current_balance, (string) $amount, 2)]);
            } elseif ($commission->bank_account_id) {
                $bank = BankAccount::lockForUpdate()->find($commission->bank_account_id);
                $bank?->update(['current_balance' => bcadd((string) $bank->current_balance, (string) $amount, 2)]);
            }

            JournalEntry::where('source_type', 'SalesExecutiveCommission')
                ->where('source_id', $commission->id)
                ->where('status', 'Posted')
                ->whereNull('reversal_of_id')
                ->get()
                ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided commission payment - {$commission->facility?->code}", $request->user()->id));

            if ($commission->commission_type === 'percentage') {
                SalesExecutiveCommission::where('credit_facility_id', $commission->credit_facility_id)
                    ->where('cycle_no', $commission->cycle_no + 1)
                    ->whereNull('paid_at')
                    ->delete();
            }

            $commission->update([
                'paid_at' => null,
                'payment_amount' => null,
                'payment_method' => null,
                'cash_account_id' => null,
                'bank_account_id' => null,
                'reference_no' => null,
                'paid_by' => null,
            ]);
        });

        return self::present($commission->fresh()->load(self::RELATIONS));
    }

    private static function paidInstallments(SalesExecutiveCommission $commission): int
    {
        return $commission->facility->schedules->where('status', 'Paid')->count();
    }

    /** Total paid-installment count this cycle needs to reach before it's eligible. */
    private static function eligibleAt(SalesExecutiveCommission $c): int
    {
        return $c->from_installment + $c->required_installments;
    }

    /** Percentage-type commissions are earned against interest collected since
     *  this cycle's baseline (from_installment) — not the whole loan's history,
     *  so an earlier cycle's interest is never counted twice — recalculated live
     *  until paid. A fixed-type commission, or one already paid, just returns its
     *  stored (frozen) amount. */
    private static function commissionAmount(SalesExecutiveCommission $c): float
    {
        if ($c->commission_type !== 'percentage' || $c->paid_at) {
            return (float) $c->commission_amount;
        }

        return round(LoanSummary::collectedInterest($c->facility, $c->from_installment) * (float) $c->commission_value / 100, 2);
    }

    /** Once a percentage-type cycle is paid, starts the next one (baselined at
     *  the loan's current paid-installment count) provided the loan still has
     *  installments left to collect interest on. Fixed-type commissions stay a
     *  single one-time payout, as before. */
    private static function maybeStartNextCycle(SalesExecutiveCommission $c): void
    {
        if ($c->commission_type !== 'percentage') {
            return;
        }
        $paidInstallments = self::paidInstallments($c);
        if ($paidInstallments >= $c->facility->schedules->count()) {
            return;
        }

        SalesExecutiveCommission::create([
            'credit_facility_id' => $c->credit_facility_id,
            'sales_executive_id' => $c->sales_executive_id,
            'loan_type_id' => $c->loan_type_id,
            'cycle_no' => $c->cycle_no + 1,
            'from_installment' => $paidInstallments,
            'commission_type' => $c->commission_type,
            'commission_value' => $c->commission_value,
            'commission_amount' => 0,
            'required_installments' => $c->required_installments,
        ]);
    }

    private static function present(SalesExecutiveCommission $c): array
    {
        $loan = $c->facility;
        $paidInstallments = self::paidInstallments($c);
        $today = Carbon::today();

        $commissionStatus = $c->paid_at
            ? 'Paid'
            : ($paidInstallments >= self::eligibleAt($c) ? 'Eligible' : 'Pending');

        $paymentStatus = $loan->status === 'Closed'
            ? 'Closed'
            : ($loan->schedules->contains(fn ($s) => $s->due_date->lessThan($today) && $s->status !== 'Paid') ? 'Overdue' : 'Current');

        return [
            'id' => $c->id,
            'loan_id' => $loan->id,
            'loan_code' => $loan->code,
            'customer_name' => $loan->customer?->full_name,
            'loan_type_id' => $c->loan_type_id,
            'loan_type_name' => $c->loanType?->name,
            'sales_executive_id' => $c->sales_executive_id,
            'sales_executive_name' => $c->salesExecutive?->name,
            'cycle_no' => $c->cycle_no,
            'loan_amount' => $loan->principal_amount,
            'commission_amount' => self::commissionAmount($c),
            'required_installments' => $c->required_installments,
            // Installments paid within THIS cycle only (capped to required_installments),
            // not the loan's total paid count — see self::eligibleAt().
            'paid_installments' => min(max(0, $paidInstallments - $c->from_installment), $c->required_installments),
            'commission_status' => $commissionStatus,
            'payment_status' => $paymentStatus,
            'paid_at' => $c->paid_at,
            'payment_amount' => $c->payment_amount,
            'payment_method' => $c->payment_method,
            'reference_no' => $c->reference_no,
            'notes' => $c->notes,
        ];
    }
}
