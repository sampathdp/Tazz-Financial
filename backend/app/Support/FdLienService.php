<?php

namespace App\Support;

use App\Models\FacilityCollateral;
use App\Models\FacilityCollateralFdDetail;
use App\Models\FacilityPayment;
use App\Models\FdLoanPolicy;
use App\Models\FdWithdrawal;
use App\Models\FixedDeposit;
use App\Models\Loan;
use App\Services\NumberGenerator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loan-against-FD rules in one place: eligibility, loan limit and rate (quote),
 * and the lien lifecycle (pledge / release / transfer).
 *
 * The lien row (facility_collateral_fd_details) is the source of truth;
 * fixed_deposits.lien_amount is its maintained cache. Every method here that
 * touches one touches the other inside the caller's transaction.
 */
class FdLienService
{
    /** Loan statuses that end a loan without the FD being used - the lien is released. */
    public const RELEASING_STATUSES = ['Rejected', 'Cancelled', 'Closed'];

    public const PLEDGED_MESSAGE = 'This FD is pledged as security for a loan and cannot be changed until the lien is released.';

    /**
     * Why this FD can't back a new loan, or null if it can. Pass the policy to
     * also check the maturity runway.
     */
    public static function ineligibleReason(FixedDeposit $fd, ?FdLoanPolicy $policy = null): ?string
    {
        if ($fd->status !== 'Active') {
            return 'Only an active FD can be pledged.';
        }
        if ($fd->is_matured) {
            return 'This FD has matured - renew or withdraw it first.';
        }
        if ($fd->is_pledged) {
            return 'This FD is already pledged to another loan.';
        }
        if (!$policy) {
            return 'Loans against this FD product are not enabled.';
        }
        if ($policy->status !== 'Active') {
            return 'Loans against this FD product are currently disabled.';
        }
        $daysLeft = (int) Carbon::today()->diffInDays(Carbon::parse($fd->maturity_date), false);
        if ($daysLeft < $policy->min_days_to_maturity) {
            return "This FD matures in {$daysLeft} day(s); at least {$policy->min_days_to_maturity} are required.";
        }

        return null;
    }

    /**
     * Limit, rate and term cap for a loan against $fd.
     *
     * @return array{eligible: bool, reason: ?string, max_loan_amount: float, min_loan_amount: float, interest_rate_percent: ?float, rate_period: string, ltv_percent: ?float, max_term_months: ?int, policy: ?FdLoanPolicy}
     */
    public static function quote(FixedDeposit $fd): array
    {
        $policy = FdLoanPolicy::where('funding_product_id', $fd->funding_product_id)->first();
        $reason = self::ineligibleReason($fd, $policy);

        $max = 0.0;
        $maxTerm = null;
        if ($policy) {
            $max = round((float) $fd->principal_amount * (float) $policy->max_ltv_percent / 100, 2);
            if ($policy->max_loan_amount !== null) {
                $max = min($max, (float) $policy->max_loan_amount);
            }
            if ($policy->cap_term_to_maturity) {
                $daysLeft = max(0, (int) Carbon::today()->diffInDays(Carbon::parse($fd->maturity_date), false));
                $maxTerm = max(1, (int) floor($daysLeft / 30));
            }
        }

        return [
            'eligible' => $reason === null,
            'reason' => $reason,
            'max_loan_amount' => $max,
            'min_loan_amount' => $policy ? (float) $policy->min_loan_amount : 0.0,
            'interest_rate_percent' => $policy ? $policy->loanRateFor($fd) : null,
            'rate_period' => 'annual',
            'ltv_percent' => $policy ? (float) $policy->max_ltv_percent : null,
            'max_term_months' => $maxTerm,
            'policy' => $policy,
        ];
    }

    /**
     * Pledges $fd to $loan. Must run inside a transaction. Re-reads the FD with
     * a row lock so two concurrent applications can't both pass the checks; the
     * unique index on active_fd_id is the backstop.
     */
    public static function pledge(Loan $loan, FixedDeposit $fd, int $userId): FacilityCollateral
    {
        $fd = FixedDeposit::whereKey($fd->id)->lockForUpdate()->firstOrFail();
        $quote = self::quote($fd);
        if (!$quote['eligible']) {
            throw ValidationException::withMessages(['fixed_deposit_id' => $quote['reason']]);
        }
        if ((int) $fd->customer_id !== (int) $loan->customer_id) {
            throw ValidationException::withMessages(['fixed_deposit_id' => 'The FD belongs to a different customer.']);
        }
        if ((float) $loan->principal_amount > $quote['max_loan_amount'] + 0.005) {
            throw ValidationException::withMessages(['principal_amount' => 'Loan amount exceeds the maximum of ' . number_format($quote['max_loan_amount'], 2) . ' allowed against this FD.']);
        }

        $collateral = $loan->collateral()->create([
            'collateral_type' => 'FixedDeposit',
            'collateral_category' => 'Fixed Deposit',
            'description' => "FD {$fd->fd_number} lien",
            'estimated_value' => $fd->principal_amount,
        ]);
        $collateral->fdDetail()->create([
            'credit_facility_id' => $loan->id,
            'fixed_deposit_id' => $fd->id,
            'fd_loan_policy_id' => $quote['policy']->id,
            'fd_principal_snapshot' => $fd->principal_amount,
            'fd_rate_snapshot' => $fd->interest_rate_percent,
            'fd_maturity_snapshot' => $fd->maturity_date,
            'ltv_percent' => $quote['ltv_percent'],
            'lien_amount' => $fd->principal_amount,
            'max_loan_value' => $quote['max_loan_amount'],
            'lien_status' => 'Active',
            'marked_at' => now(),
            'marked_by' => $userId,
        ]);
        $fd->update(['lien_amount' => $fd->principal_amount]);

        return $collateral;
    }

    /** Releases every Active lien held by $loan and frees the FDs. Safe to call repeatedly. */
    public static function releaseForLoan(Loan $loan, string $reason, ?int $userId = null): void
    {
        $liens = FacilityCollateralFdDetail::where('credit_facility_id', $loan->id)
            ->where('lien_status', 'Active')->lockForUpdate()->get();
        foreach ($liens as $lien) {
            $lien->update([
                'lien_status' => 'Released',
                'released_at' => now(),
                'released_by' => $userId ?? auth()->id(),
                'release_reason' => $reason,
            ]);
            FixedDeposit::whereKey($lien->fixed_deposit_id)->update(['lien_amount' => 0]);
        }
    }

    /**
     * Closes the pledged FD and applies its payout to the loan; any excess goes to the
     * customer. Payout is principal, plus the FD's interest when it is a matured
     * maturity-method FD (a premature close forfeits interest, so only principal).
     * If the payout is smaller than the balance, the loan stays Active with the
     * shortfall unsecured. No cash is received for the loan side, so the repayment is
     * a payment_method='FdAdjustment' FacilityPayment; only the excess touches the till.
     *
     * @param array{excess_payment_method?: string, reference_no?: ?string, notes?: ?string, allow_premature?: bool} $opts
     * @return array{withdrawal: FdWithdrawal, payment: FacilityPayment, applied: float, excess: float, remaining_balance: float}
     */
    public static function settle(Loan $loan, int $userId, array $opts = []): array
    {
        return DB::transaction(function () use ($loan, $userId, $opts) {
            $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($loan->status !== 'Active') {
                throw ValidationException::withMessages(['loan' => 'Only an active loan can be settled from its FD.']);
            }
            $lien = FacilityCollateralFdDetail::where('credit_facility_id', $loan->id)->where('lien_status', 'Active')->lockForUpdate()->first();
            if (!$lien) {
                throw ValidationException::withMessages(['loan' => 'This loan has no active FD lien.']);
            }
            $fd = FixedDeposit::with('branch')->whereKey($lien->fixed_deposit_id)->lockForUpdate()->firstOrFail();
            if ($fd->status !== 'Active') {
                throw ValidationException::withMessages(['loan' => 'The pledged FD is not active.']);
            }
            if (!$fd->is_matured && empty($opts['allow_premature'])) {
                throw ValidationException::withMessages(['loan' => 'The FD has not matured. Confirm premature closure to settle early (interest is forfeited).']);
            }
            if ($fd->is_matured && $fd->interest_payment_method === 'monthly' && $fd->interestPayments()->where('status', 'Pending')->exists()) {
                throw ValidationException::withMessages(['loan' => 'Settle all due monthly interest payments on the FD first.']);
            }

            $today = Carbon::today();
            $principal = (float) $fd->principal_amount;
            $interest = $fd->is_matured && $fd->interest_payment_method === 'maturity' ? (float) $fd->total_interest_amount : 0.0;
            $payout = round($principal + $interest, 2);
            $balance = LoanPaymentAllocator::outstandingBalance($loan, $today);
            if ($balance <= 0) {
                throw ValidationException::withMessages(['loan' => 'This loan has no outstanding balance.']);
            }
            $applied = round(min($payout, $balance), 2);
            $excess = round($payout - $applied, 2);

            $voucherNo = NumberGenerator::next('fd_withdrawal', [
                'branch_id' => $fd->branch_id,
                'branch_code' => $fd->branch->code,
                'user_id' => $userId,
            ]);
            $withdrawal = FdWithdrawal::create([
                'fixed_deposit_id' => $fd->id,
                'voucher_no' => $voucherNo,
                'principal_amount' => $principal,
                'interest_amount' => $interest,
                'total_amount' => $payout,
                'adjusted_amount' => $applied,
                'settled_credit_facility_id' => $loan->id,
                'payment_method' => $opts['excess_payment_method'] ?? 'Cash',
                'reference_no' => $opts['reference_no'] ?? null,
                'notes' => $opts['notes'] ?? "Adjusted against loan {$loan->code}",
                'closed_at' => $today->toDateString(),
                'created_by' => $userId,
            ]);
            NumberGenerator::attach($voucherNo, $withdrawal);

            // Lien first: applyAllocation() below may close the loan, and the Loan observer
            // would otherwise record this as a plain release instead of a settlement.
            $lien->update([
                'lien_status' => 'Settled',
                'released_at' => now(),
                'released_by' => $userId,
                'release_reason' => "FD adjusted against loan {$loan->code}",
                'settled_amount' => $applied,
                'settlement_withdrawal_id' => $withdrawal->id,
            ]);
            $fd->update(['status' => 'Closed', 'closed_at' => $today->toDateString(), 'lien_amount' => 0]);

            $paymentCode = NumberGenerator::next('payment', [
                'branch_id' => $loan->branch_id,
                'branch_code' => $fd->branch->code,
                'type_code' => 'CA',
                'user_id' => $userId,
            ]);
            $payment = FacilityPayment::create([
                'code' => $paymentCode,
                'credit_facility_id' => $loan->id,
                'branch_id' => $loan->branch_id,
                'amount' => $applied,
                'payment_method' => 'FdAdjustment',
                'payment_type' => 'Regular',
                'clearance_status' => null,
                'received_by' => $userId,
                'paid_at' => now(),
                'notes' => "FD {$fd->fd_number} adjusted ({$voucherNo})",
                'approval_status' => 'Approved',
            ]);
            NumberGenerator::attach($paymentCode, $payment);
            LoanPaymentAllocator::applyAllocation($loan, $payment, $applied, $today);

            $credits = LoanLedgerPoster::creditLinesFor($payment);
            if (abs(array_sum(array_column($credits, 'credit')) - $applied) > 0.01) {
                throw ValidationException::withMessages(['loan' => 'Could not allocate the FD proceeds cleanly to the loan; nothing was changed.']);
            }
            $withdrawal->setRelation('fixedDeposit', $fd);
            FdLedgerPoster::postLoanAdjustment($withdrawal, $credits);

            return [
                'withdrawal' => $withdrawal,
                'payment' => $payment,
                'applied' => $applied,
                'excess' => $excess,
                'remaining_balance' => round(max(0, $balance - $applied), 2),
            ];
        });
    }

    /** Re-points the Active liens of a rescheduled loan at its replacement; the FD stays pledged throughout. */
    public static function transfer(Loan $from, Loan $to): void
    {
        $ids = FacilityCollateral::where('credit_facility_id', $from->id)->where('collateral_type', 'FixedDeposit')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        FacilityCollateral::whereIn('id', $ids)->update(['credit_facility_id' => $to->id]);
        FacilityCollateralFdDetail::whereIn('facility_collateral_id', $ids)->update(['credit_facility_id' => $to->id]);
    }

    /** 422 response payload for FD actions blocked by a lien, or null when the FD is free. */
    public static function guard(FixedDeposit $fd): ?\Illuminate\Http\JsonResponse
    {
        if (!$fd->is_pledged) {
            return null;
        }
        $lien = FacilityCollateralFdDetail::where('fixed_deposit_id', $fd->id)->where('lien_status', 'Active')->with('facility:id,code')->first();

        return response()->json([
            'message' => self::PLEDGED_MESSAGE . ($lien?->facility ? " Loan: {$lien->facility->code}." : ''),
        ], 422);
    }
}
