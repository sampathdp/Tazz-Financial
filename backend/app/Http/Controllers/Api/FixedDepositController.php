<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FdDeposit;
use App\Models\FdPartPayment;
use App\Models\FdRenewal;
use App\Models\FdWithdrawal;
use App\Models\FixedDeposit;
use App\Models\FundingProduct;
use App\Services\NumberGenerator;
use App\Support\FdInterestCalculator;
use App\Support\FdLedgerPoster;
use App\Support\FdLienService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FixedDepositController extends Controller
{
    private const PAYMENT_METHODS = ['Cash', 'Bank', 'Cheque'];

    private const INTEREST_METHODS = ['monthly', 'maturity'];

    private const AUTO_RENEW_MODES = ['principal_only', 'principal_and_interest'];

    private const SUMMARY_RELATIONS = ['customer:id,full_name,code,nic_no,phone', 'branch:id,name,code', 'fundingProduct:id,name,code,interest_method'];

    private const DETAIL_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone,email,address',
        'branch:id,name,code',
        'fundingProduct',
        'deposits.bank:id,name',
        'interestPayments.payer:id,name',
        'withdrawal',
        'partPayments.creator:id,name',
        'renewedFrom:id,fd_number',
        'renewedTo:id,fd_number',
    ];

    public function index(Request $request)
    {
        $q = FixedDeposit::with(self::SUMMARY_RELATIONS)->orderByDesc('id');

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($customerId = $request->query('customerId')) {
            $q->where('customer_id', $customerId);
        }
        if ($request->boolean('matured')) {
            $q->where('status', 'Active')->whereDate('maturity_date', '<=', now());
        }
        if ($days = $request->query('maturingWithinDays')) {
            $q->where('status', 'Active')
                ->whereDate('maturity_date', '>=', now())
                ->whereDate('maturity_date', '<=', now()->addDays((int) $days));
        }

        return $q->get();
    }

    /**
     * Admin-only correction of rate / term / start date / interest method. The principal is
     * tied to the allocated deposit's posted cash, so it is not editable. Only allowed before
     * any interest payment, part payment, lien, renewal or withdrawal.
     */
    public function adminUpdate(Request $request, FixedDeposit $fixedDeposit)
    {
        if ($fixedDeposit->status !== 'Active' || $fixedDeposit->renewed_to_id || $fixedDeposit->withdrawal()->exists()) {
            return response()->json(['message' => 'Only an active, un-renewed fixed deposit can be edited.'], 422);
        }
        if ($fixedDeposit->interestPayments()->where('paid_amount', '>', 0)->exists()
            || $fixedDeposit->partPayments()->exists()
            || (float) $fixedDeposit->lien_amount > 0) {
            return response()->json(['message' => 'This fixed deposit already has a payment, part payment or lien and can no longer be edited.'], 422);
        }

        $data = $request->validate([
            'interest_rate_percent' => 'required|numeric|min:0|max:100',
            'term_months' => 'required|integer|min:1|max:600',
            'start_date' => 'required|date',
            'interest_payment_method' => ['required', Rule::in(self::INTEREST_METHODS)],
        ]);

        DB::transaction(function () use ($fixedDeposit, $data) {
            $calc = FdInterestCalculator::calculate((float) $fixedDeposit->principal_amount, (float) $data['interest_rate_percent'], (int) $data['term_months'], $data['interest_payment_method']);
            $fixedDeposit->update([
                'interest_rate_percent' => $data['interest_rate_percent'],
                'interest_payment_method' => $data['interest_payment_method'],
                'term_months' => $data['term_months'],
                'start_date' => $data['start_date'],
                'maturity_date' => Carbon::parse($data['start_date'])->addMonthsNoOverflow((int) $data['term_months'])->format('Y-m-d'),
                'monthly_interest_amount' => $calc['monthlyInterestAmount'],
                'total_interest_amount' => $calc['totalInterestAmount'],
                'maturity_amount' => $calc['maturityAmount'],
                'next_interest_date' => null,
            ]);
            $fixedDeposit->interestPayments()->delete();
            if ($data['interest_payment_method'] === 'monthly') {
                $rows = FdInterestCalculator::generateSchedule($fixedDeposit->fresh());
                $fixedDeposit->interestPayments()->insert($rows);
                $fixedDeposit->update(['next_interest_date' => $rows[0]['due_date'] ?? null]);
            }
        });

        return $this->show($fixedDeposit->fresh());
    }

    public function show(FixedDeposit $fixedDeposit)
    {
        return $fixedDeposit->load(self::DETAIL_RELATIONS);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'deposit_id' => 'required|exists:fd_deposits,id',
            'funding_product_id' => 'required|exists:funding_products,id',
            'term_months' => 'required|integer|min:1',
            'start_date' => 'required|date',
            'interest_payment_method' => ['nullable', Rule::in(self::INTEREST_METHODS)],
            'auto_renew' => 'nullable|boolean',
            'auto_renew_mode' => ['nullable', Rule::in(self::AUTO_RENEW_MODES)],
            'auto_renew_term_months' => 'nullable|integer|min:1',
        ]);

        $deposit = FdDeposit::findOrFail($data['deposit_id']);
        if ($deposit->status !== 'Available') {
            throw ValidationException::withMessages(['deposit_id' => 'This deposit has already been allocated to a Fixed Deposit.']);
        }

        $product = FundingProduct::findOrFail($data['funding_product_id']);
        if ($product->status !== 'Active') {
            throw ValidationException::withMessages(['funding_product_id' => 'This funding product is inactive.']);
        }
        $this->assertAmountWithinProduct((float) $deposit->amount, $product);
        $this->assertTermAllowed((int) $data['term_months'], $product);
        $interestMethod = $this->resolveInterestMethod($data['interest_payment_method'] ?? null, $product);

        $fd = DB::transaction(function () use ($data, $deposit, $product, $interestMethod, $request) {
            $startDate = Carbon::parse($data['start_date']);
            $fd = $this->createFixedDeposit(
                branchId: $deposit->branch_id,
                customerId: $deposit->customer_id,
                product: $product,
                principal: (float) $deposit->amount,
                termMonths: (int) $data['term_months'],
                startDate: $startDate,
                userId: $request->user()->id,
                interestMethod: $interestMethod,
                autoRenew: $this->autoRenewColumns($data, $product),
            );

            // The deposit's cash was already posted to the cashbook/ledger when it
            // was recorded (see FdDepositController::store()) — allocating it to
            // this FD is purely administrative, no further cash movement.
            $deposit->update(['status' => 'Allocated', 'fixed_deposit_id' => $fd->id]);

            return $fd;
        });

        return response()->json($fd->load(self::DETAIL_RELATIONS), 201);
    }

    /** Renews a matured FD for a new term, optionally topping up the principal with a fresh deposit (capital enhancement). */
    public function renew(Request $request, FixedDeposit $fixedDeposit)
    {
        if ($blocked = FdLienService::guard($fixedDeposit)) {
            return $blocked;
        }
        if (!$fixedDeposit->is_matured) {
            return response()->json(['message' => 'This FD has not matured yet and cannot be renewed.'], 422);
        }

        $data = $request->validate([
            'term_months' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255',
            // Interest-at-maturity FDs only: what happens to the interest earned over the old term.
            'interest_treatment' => ['nullable', Rule::in(['payout', 'capitalise'])],
            'auto_renew' => 'nullable|boolean',
            'auto_renew_mode' => ['nullable', Rule::in(self::AUTO_RENEW_MODES)],
            'auto_renew_term_months' => 'nullable|integer|min:1',
            'enhancement' => 'nullable|array',
            'enhancement.amount' => 'required_with:enhancement|numeric|min:0.01',
            'enhancement.deposit_date' => 'nullable|date',
            'enhancement.payment_method' => ['required_with:enhancement', Rule::in(self::PAYMENT_METHODS)],
            'enhancement.reference_no' => 'nullable|string|max:64',
            'enhancement.bank_id' => 'nullable|exists:banks,id',
            'enhancement.bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'enhancement.notes' => 'nullable|string',
        ]);

        $fixedDeposit->loadMissing(['fundingProduct', 'branch']);
        $product = $fixedDeposit->fundingProduct;
        $this->assertTermAllowed((int) $data['term_months'], $product);

        // Not sent = keep the instruction the old FD carried; sent = whatever the operator just chose.
        $autoRenew = array_key_exists('auto_renew', $data) && $data['auto_renew'] !== null
            ? $this->autoRenewColumns($data, $product)
            : $this->carriedAutoRenew($fixedDeposit);

        [$newFd, $enhancementDeposit] = DB::transaction(fn () => $this->performRenewal(
            $fixedDeposit,
            (int) $data['term_months'],
            $data['enhancement'] ?? null,
            $data['notes'] ?? null,
            $fixedDeposit->interest_payment_method === 'maturity' ? ($data['interest_treatment'] ?? 'none') : 'none',
            (int) $request->user()->id,
            false,
            $autoRenew,
        ));

        return response()->json([
            'fixed_deposit' => $newFd->load(self::DETAIL_RELATIONS),
            'enhancement_deposit' => $enhancementDeposit,
        ], 201);
    }

    /**
     * Rolls a matured FD into a new one. Called inside a transaction by renew() (operator) and by
     * fd:auto-renew (customer's standing instruction). $interestTreatment only matters for an
     * interest-at-maturity FD: 'capitalise' adds the earned interest to the new principal,
     * 'payout' pays it to the customer in cash, 'none' (legacy) leaves it unsettled.
     *
     * @return array{0: FixedDeposit, 1: ?FdDeposit}
     */
    public function performRenewal(FixedDeposit $fixedDeposit, int $termMonths, ?array $enhancement, ?string $notes, string $interestTreatment, int $userId, bool $auto, array $autoRenew): array
    {
        $fixedDeposit->loadMissing(['fundingProduct', 'branch']);
        $product = $fixedDeposit->fundingProduct;

        $enhancementAmount = (float) ($enhancement['amount'] ?? 0);
        $interest = $interestTreatment === 'none' ? 0.0 : (float) $fixedDeposit->total_interest_amount;
        $capitalised = $interestTreatment === 'capitalise' ? $interest : 0.0;
        $newPrincipal = round((float) $fixedDeposit->principal_amount + $capitalised + $enhancementAmount, 2);
        $newStart = Carbon::parse($fixedDeposit->maturity_date);

        $newFd = $this->createFixedDeposit(
            branchId: $fixedDeposit->branch_id,
            customerId: $fixedDeposit->customer_id,
            product: $product,
            principal: $newPrincipal,
            termMonths: $termMonths,
            startDate: $newStart,
            userId: $userId,
            // Carries the method the customer originally chose forward, even
            // if the product's own default has since been changed.
            interestMethod: $fixedDeposit->interest_payment_method,
            renewedFromId: $fixedDeposit->id,
            autoRenew: $autoRenew,
        );

        // Only the enhancement portion is a real cash event — the old FD's
        // principal simply rolls forward into the new FD's principal_amount
        // above, with no cash changing hands (see FdLedgerPoster's docblock).
        $enhancementDeposit = null;
        if ($enhancementAmount > 0) {
            $depositNumber = NumberGenerator::next('fd_deposit', [
                'branch_id' => $fixedDeposit->branch_id,
                'branch_code' => $fixedDeposit->branch->code,
                'user_id' => $userId,
            ]);
            $enhancementDeposit = FdDeposit::create([
                'deposit_number' => $depositNumber,
                'branch_id' => $fixedDeposit->branch_id,
                'customer_id' => $fixedDeposit->customer_id,
                'deposit_date' => $enhancement['deposit_date'] ?? now()->format('Y-m-d'),
                'amount' => $enhancementAmount,
                'payment_method' => $enhancement['payment_method'],
                'reference_no' => $enhancement['reference_no'] ?? null,
                'bank_id' => $enhancement['bank_id'] ?? null,
                'bank_account_id' => $enhancement['bank_account_id'] ?? null,
                'notes' => $enhancement['notes'] ?? null,
                // Created already-allocated (unlike FdDepositController::store()'s
                // normal Available-first flow) since it's funding this new FD
                // in the same breath it's recorded.
                'status' => 'Allocated',
                'fixed_deposit_id' => $newFd->id,
                'created_by' => $userId,
            ]);
            NumberGenerator::attach($depositNumber, $enhancementDeposit);

            FdLedgerPoster::postDepositReceived($enhancementDeposit);
        }

        $fixedDeposit->update([
            'status' => 'Renewed',
            'renewed_to_id' => $newFd->id,
            'closed_at' => now()->format('Y-m-d'),
        ]);

        $renewal = FdRenewal::create([
            'old_fixed_deposit_id' => $fixedDeposit->id,
            'new_fixed_deposit_id' => $newFd->id,
            'old_principal' => $fixedDeposit->principal_amount,
            'additional_deposit_amount' => $enhancementAmount,
            'new_principal' => $newPrincipal,
            'interest_amount' => $interest,
            'capitalised_interest' => $capitalised,
            'interest_payment_method' => $interestTreatment === 'payout' ? 'Cash' : null,
            'is_auto' => $auto,
            'additional_deposit_id' => $enhancementDeposit?->id,
            'new_term_months' => $termMonths,
            'renewed_at' => now()->format('Y-m-d'),
            'notes' => $notes,
            'created_by' => $userId,
        ]);
        FdLedgerPoster::postRenewalInterest($renewal, $fixedDeposit);

        return [$newFd, $enhancementDeposit];
    }

    /** Sets or clears the customer's auto-renew instruction on a live FD. */
    public function setAutoRenew(Request $request, FixedDeposit $fixedDeposit)
    {
        if ($fixedDeposit->status !== 'Active') {
            return response()->json(['message' => 'Auto-renew can only be changed on an active FD.'], 422);
        }
        $data = $request->validate([
            'auto_renew' => 'required|boolean',
            'auto_renew_mode' => ['nullable', Rule::in(self::AUTO_RENEW_MODES)],
            'auto_renew_term_months' => 'nullable|integer|min:1',
        ]);
        $fixedDeposit->loadMissing('fundingProduct');
        $fixedDeposit->update($this->autoRenewColumns($data, $fixedDeposit->fundingProduct));

        return $fixedDeposit->fresh()->load(self::DETAIL_RELATIONS);
    }

    /** Validated request fields -> fixed_deposits auto-renew columns (all cleared when switched off). */
    private function autoRenewColumns(array $data, FundingProduct $product): array
    {
        if (empty($data['auto_renew'])) {
            return ['auto_renew' => false, 'auto_renew_mode' => null, 'auto_renew_term_months' => null];
        }
        $term = isset($data['auto_renew_term_months']) ? (int) $data['auto_renew_term_months'] : null;
        if ($term !== null) {
            $this->assertTermAllowed($term, $product);
        }

        return [
            'auto_renew' => true,
            'auto_renew_mode' => $data['auto_renew_mode'] ?? 'principal_only',
            'auto_renew_term_months' => $term,
        ];
    }

    private function carriedAutoRenew(FixedDeposit $fd): array
    {
        return [
            'auto_renew' => (bool) $fd->auto_renew,
            'auto_renew_mode' => $fd->auto_renew ? $fd->auto_renew_mode : null,
            'auto_renew_term_months' => $fd->auto_renew ? $fd->auto_renew_term_months : null,
        ];
    }

    /** Pays out principal (+ maturity-method interest) and closes the FD. */
    public function withdraw(Request $request, FixedDeposit $fixedDeposit)
    {
        if ($blocked = FdLienService::guard($fixedDeposit)) {
            return $blocked;
        }
        $fixedDeposit->loadMissing('branch');
        if (!$fixedDeposit->is_matured) {
            return response()->json(['message' => 'This FD has not matured yet and cannot be withdrawn.'], 422);
        }
        if ($fixedDeposit->interest_payment_method === 'monthly' && $fixedDeposit->interestPayments()->where('status', 'Pending')->exists()) {
            return response()->json(['message' => 'Settle all due monthly interest payments before closing this FD.'], 422);
        }

        $data = $request->validate([
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            // Paying out by Bank/Cheque needs the company account it is paid from.
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id', 'required_if:payment_method,Bank,Cheque'],
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'closed_at' => 'nullable|date',
            // Interest for days held past maturity. Rate defaults to the product's post-maturity rate;
            // the amount defaults to the computed figure but the operator may override it (e.g. to match what was agreed).
            'post_maturity_rate_percent' => 'nullable|numeric|min:0|max:999',
            'post_maturity_interest' => ['nullable', 'numeric', 'min:0', new \App\Rules\Money],
        ]);

        $withdrawal = DB::transaction(function () use ($fixedDeposit, $data, $request) {
            $closedAt = $data['closed_at'] ?? now()->format('Y-m-d');
            $principalAmount = (float) $fixedDeposit->principal_amount;

            $postMaturity = $this->postMaturityInterest(
                $fixedDeposit,
                $closedAt,
                isset($data['post_maturity_rate_percent']) ? (float) $data['post_maturity_rate_percent'] : null,
            );
            $postMaturityInterest = isset($data['post_maturity_interest']) ? round((float) $data['post_maturity_interest'], 2) : $postMaturity['interest'];

            $interestAmount = round(
                ($fixedDeposit->interest_payment_method === 'maturity' ? (float) $fixedDeposit->total_interest_amount : 0.0) + $postMaturityInterest,
                2,
            );

            $voucherNo = NumberGenerator::next('fd_withdrawal', [
                'branch_id' => $fixedDeposit->branch_id,
                'branch_code' => $fixedDeposit->branch->code,
                'user_id' => $request->user()->id,
            ]);

            $withdrawal = FdWithdrawal::create([
                'fixed_deposit_id' => $fixedDeposit->id,
                'voucher_no' => $voucherNo,
                'principal_amount' => $principalAmount,
                'interest_amount' => $interestAmount,
                'total_amount' => $principalAmount + $interestAmount,
                'post_maturity_days' => $postMaturity['days'],
                'post_maturity_rate_percent' => $postMaturity['rate'],
                'post_maturity_interest' => $postMaturityInterest,
                'payment_method' => $data['payment_method'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'closed_at' => $closedAt,
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($voucherNo, $withdrawal);

            $fixedDeposit->update(['status' => 'Closed', 'closed_at' => $closedAt]);

            $withdrawal->setRelation('fixedDeposit', $fixedDeposit);
            FdLedgerPoster::postWithdrawal($withdrawal);

            return $withdrawal;
        });

        return response()->json($withdrawal->load(['fixedDeposit' => fn ($q) => $q->with(self::DETAIL_RELATIONS)]), 201);
    }

    /**
     * Interest for the days an FD was held past its maturity date before being withdrawn:
     * principal x rate x days / 365 (same simple-interest basis as partPayment()). The rate
     * is the operator's override, else the product's post_maturity_rate_percent, else 0.
     *
     * @return array{days: int, rate: ?float, interest: float}
     */
    private function postMaturityInterest(FixedDeposit $fd, string $closedAt, ?float $rateOverride): array
    {
        $fd->loadMissing('fundingProduct');
        $days = (int) max(0, Carbon::parse($fd->maturity_date)->startOfDay()->diffInDays(Carbon::parse($closedAt)->startOfDay(), false));
        $rate = $rateOverride ?? ($fd->fundingProduct->post_maturity_rate_percent !== null ? (float) $fd->fundingProduct->post_maturity_rate_percent : null);

        return [
            'days' => $days,
            'rate' => $rate,
            'interest' => $rate ? round((float) $fd->principal_amount * ($rate / 100) * ($days / 365), 2) : 0.0,
        ];
    }

    /** Read-only preview for the Withdraw screen: late days and the default post-maturity interest for a given closing date / rate. */
    public function withdrawalPreview(Request $request, FixedDeposit $fixedDeposit)
    {
        $data = $request->validate([
            'closed_at' => 'nullable|date',
            'post_maturity_rate_percent' => 'nullable|numeric|min:0|max:999',
        ]);
        $p = $this->postMaturityInterest(
            $fixedDeposit,
            $data['closed_at'] ?? now()->format('Y-m-d'),
            isset($data['post_maturity_rate_percent']) ? (float) $data['post_maturity_rate_percent'] : null,
        );

        return response()->json([
            'post_maturity_days' => $p['days'],
            'post_maturity_rate_percent' => $p['rate'],
            'post_maturity_interest' => $p['interest'],
            'maturity_interest' => $fixedDeposit->interest_payment_method === 'maturity' ? (float) $fixedDeposit->total_interest_amount : 0.0,
        ]);
    }

    /**
     * Undoes a mistaken closure — reverses the ledger/till entries, reopens
     * the FD (Active, closed_at cleared), and deletes the withdrawal row
     * (FdWithdrawal has no Voided status of its own to flip, unlike
     * FacilityPayment, so removal is the only way to represent "this never
     * happened" — the reversed journal entries are the permanent audit
     * trail of the correction, same as everywhere else in this codebase).
     */
    public function voidWithdrawal(Request $request, FixedDeposit $fixedDeposit)
    {
        $withdrawal = $fixedDeposit->withdrawal;
        if (!$withdrawal) {
            return response()->json(['message' => 'This FD has no withdrawal to void.'], 422);
        }
        if ($withdrawal->settled_credit_facility_id) {
            return response()->json(['message' => 'This FD was closed against a loan - the loan repayment and lien settlement cannot be voided from here.'], 422);
        }

        DB::transaction(function () use ($fixedDeposit, $withdrawal, $request) {
            FdLedgerPoster::reverseWithdrawal($withdrawal, $request->user()->id);
            $fixedDeposit->update(['status' => 'Active', 'closed_at' => null]);
            $withdrawal->delete();
        });

        return $fixedDeposit->fresh()->load(self::DETAIL_RELATIONS);
    }

    /**
     * Payout maths for closing a whole FD before maturity: the full principal earns the
     * product's premature rate for the days held (same simple-interest basis as partPayment()).
     * Interest already paid out monthly is netted off — a shortfall is paid now, a surplus is
     * clawed back from the principal returned.
     *
     * @return array{rate: float, held_days: int, entitlement: float, paid_so_far: float, interest_paid: float, clawback: float, principal: float, total: float}
     */
    private function prematureFigures(FixedDeposit $fd, string $closedAt, ?float $rateOverride = null): array
    {
        $fd->loadMissing('fundingProduct');
        $rate = $rateOverride ?? (float) $fd->fundingProduct->premature_withdrawal_rate_percent;
        $principal = (float) $fd->principal_amount;
        $heldDays = max(1, (int) Carbon::parse($fd->start_date)->startOfDay()->diffInDays(Carbon::parse($closedAt)->startOfDay(), false));
        $entitlement = round($principal * ($rate / 100) * ($heldDays / 365), 2);
        $paidSoFar = $fd->interest_payment_method === 'monthly'
            ? round((float) $fd->interestPayments()->where('status', 'Paid')->sum('paid_amount'), 2)
            : 0.0;
        $delta = round($entitlement - $paidSoFar, 2);
        $interestPaid = max(0.0, $delta);
        $clawback = max(0.0, -$delta);

        return [
            'rate' => $rate,
            'held_days' => $heldDays,
            'entitlement' => $entitlement,
            'paid_so_far' => $paidSoFar,
            'interest_paid' => $interestPaid,
            'clawback' => $clawback,
            'principal' => $principal,
            'total' => round($principal + $interestPaid - $clawback, 2),
        ];
    }

    /** Why this FD can't be closed early, or null if it can. */
    private function prematureBlockReason(FixedDeposit $fd): ?string
    {
        if ($fd->status !== 'Active') {
            return 'Only an active FD can be withdrawn.';
        }
        if ($fd->is_matured) {
            return 'This FD has matured - use the normal Withdraw instead.';
        }
        $fd->loadMissing('fundingProduct');
        if ($fd->fundingProduct->premature_withdrawal_rate_percent === null) {
            return 'This funding product has no premature withdrawal rate configured - set one on Funding Products first.';
        }

        return null;
    }

    /** Read-only preview of an early full closure for a closing date. */
    public function prematurePreview(Request $request, FixedDeposit $fixedDeposit)
    {
        $data = $request->validate([
            'closed_at' => 'nullable|date',
            'premature_rate_percent' => 'nullable|numeric|min:0|max:999',
        ]);
        $reason = $this->prematureBlockReason($fixedDeposit);
        if ($reason) {
            return response()->json(['message' => $reason], 422);
        }

        return response()->json($this->prematureFigures(
            $fixedDeposit,
            $data['closed_at'] ?? now()->format('Y-m-d'),
            isset($data['premature_rate_percent']) ? (float) $data['premature_rate_percent'] : null,
        ));
    }

    /** Closes the whole FD before maturity at the product's premature rate and pays out principal + entitled interest. */
    public function prematureWithdraw(Request $request, FixedDeposit $fixedDeposit)
    {
        if ($blocked = FdLienService::guard($fixedDeposit)) {
            return $blocked;
        }
        $reason = $this->prematureBlockReason($fixedDeposit);
        if ($reason) {
            return response()->json(['message' => $reason], 422);
        }
        $fixedDeposit->loadMissing('branch');

        $data = $request->validate([
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id', 'required_if:payment_method,Bank,Cheque'],
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'closed_at' => 'nullable|date|before_or_equal:today',
            'premature_rate_percent' => 'nullable|numeric|min:0|max:999',
        ]);

        $withdrawal = DB::transaction(function () use ($fixedDeposit, $data, $request) {
            $closedAt = $data['closed_at'] ?? now()->format('Y-m-d');
            $f = $this->prematureFigures(
                $fixedDeposit,
                $closedAt,
                isset($data['premature_rate_percent']) ? (float) $data['premature_rate_percent'] : null,
            );
            if ($f['total'] <= 0) {
                throw ValidationException::withMessages(['closed_at' => 'Interest already paid exceeds the principal - nothing is payable. Review the FD before closing.']);
            }

            $voucherNo = NumberGenerator::next('fd_withdrawal', [
                'branch_id' => $fixedDeposit->branch_id,
                'branch_code' => $fixedDeposit->branch->code,
                'user_id' => $request->user()->id,
            ]);
            $withdrawal = FdWithdrawal::create([
                'fixed_deposit_id' => $fixedDeposit->id,
                'voucher_no' => $voucherNo,
                'principal_amount' => $f['principal'],
                'interest_amount' => $f['interest_paid'],
                'total_amount' => $f['total'],
                'payment_method' => $data['payment_method'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'closed_at' => $closedAt,
                'created_by' => $request->user()->id,
                'is_premature' => true,
                'premature_rate_percent' => $f['rate'],
                'premature_held_days' => $f['held_days'],
                'premature_clawback' => $f['clawback'],
            ]);
            NumberGenerator::attach($voucherNo, $withdrawal);

            $fixedDeposit->update(['status' => 'Closed', 'closed_at' => $closedAt]);

            $withdrawal->setRelation('fixedDeposit', $fixedDeposit);
            FdLedgerPoster::postWithdrawal($withdrawal);

            return $withdrawal;
        });

        return response()->json($withdrawal->load(['fixedDeposit' => fn ($q) => $q->with(self::DETAIL_RELATIONS)]), 201);
    }

    /**
     * Premature partial withdrawal — pays out part of the principal before
     * maturity while keeping the FD open on the reduced balance, following
     * the standard Sri Lankan LFC/bank convention: the withdrawn portion
     * earns interest at the product's own (reduced) premature-withdrawal
     * rate for the days it was actually held, not the full contracted rate/
     * term — see FundingProduct::premature_withdrawal_rate_percent. If a
     * monthly-method FD already paid out more on that portion than it's now
     * entitled to, the excess is clawed back from the principal returned
     * (mirrors bank T&Cs: "penalty may have to be deducted from capital if
     * interest has been paid monthly"). The remaining FD is recalculated
     * forward from today at the product's normal rate — past/paid interest
     * is left untouched.
     */
    public function partPayment(Request $request, FixedDeposit $fixedDeposit)
    {
        if ($blocked = FdLienService::guard($fixedDeposit)) {
            return $blocked;
        }
        if ($fixedDeposit->status !== 'Active') {
            return response()->json(['message' => 'Only an active FD can be part-paid.'], 422);
        }
        $fixedDeposit->loadMissing(['branch', 'fundingProduct']);
        $product = $fixedDeposit->fundingProduct;
        if ($product->premature_withdrawal_rate_percent === null) {
            return response()->json(['message' => 'This funding product has no premature withdrawal rate configured — set one on Funding Products before allowing part payments.'], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id', 'required_if:payment_method,Bank,Cheque'],
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'paid_at' => 'nullable|date',
        ]);

        $withdrawAmount = round((float) $data['amount'], 2);
        $principal = (float) $fixedDeposit->principal_amount;
        if ($withdrawAmount >= $principal) {
            throw ValidationException::withMessages(['amount' => 'A part payment must be less than the full principal — use Withdraw to close the FD instead.']);
        }
        $remaining = round($principal - $withdrawAmount, 2);
        if ($remaining < (float) $product->min_deposit_amount) {
            throw ValidationException::withMessages(['amount' => "The remaining balance ({$remaining}) would fall below this product's minimum deposit of {$product->min_deposit_amount}."]);
        }

        $partPayment = DB::transaction(function () use ($fixedDeposit, $product, $data, $withdrawAmount, $principal, $remaining, $request) {
            $paidAt = $data['paid_at'] ?? now()->format('Y-m-d');
            $heldDays = max(1, Carbon::parse($fixedDeposit->start_date)->diffInDays(Carbon::parse($paidAt)));
            $rate = (float) $product->premature_withdrawal_rate_percent;
            $entitlement = round($withdrawAmount * ($rate / 100) * ($heldDays / 365), 2);

            $interestPaid = 0.0;
            $clawback = 0.0;
            if ($fixedDeposit->interest_payment_method === 'monthly') {
                // Prior monthly payouts were made against the whole principal, not
                // any one part of it — apportion the withdrawn share of what's
                // already been paid, pro-rata by amount, to compare against what
                // that share is actually entitled to at the reduced rate.
                $paidSoFar = (float) $fixedDeposit->interestPayments()->where('status', 'Paid')->sum('paid_amount');
                $attributed = round($paidSoFar * ($withdrawAmount / $principal), 2);
                $clawback = max(0.0, round($attributed - $entitlement, 2));
            } else {
                $interestPaid = $entitlement;
            }
            $netAmount = round($withdrawAmount + $interestPaid - $clawback, 2);

            $voucherNo = NumberGenerator::next('fd_part_payment', [
                'branch_id' => $fixedDeposit->branch_id,
                'branch_code' => $fixedDeposit->branch->code,
                'user_id' => $request->user()->id,
            ]);

            $partPayment = FdPartPayment::create([
                'fixed_deposit_id' => $fixedDeposit->id,
                'voucher_no' => $voucherNo,
                'principal_amount' => $withdrawAmount,
                'rate_percent' => $rate,
                'held_days' => $heldDays,
                'interest_entitlement' => $entitlement,
                'interest_paid' => $interestPaid,
                'clawback_amount' => $clawback,
                'net_amount_paid' => $netAmount,
                'payment_method' => $data['payment_method'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_at' => $paidAt,
                'principal_before' => $principal,
                'monthly_interest_before' => $fixedDeposit->monthly_interest_amount,
                'total_interest_before' => $fixedDeposit->total_interest_amount,
                'maturity_amount_before' => $fixedDeposit->maturity_amount,
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($voucherNo, $partPayment);

            $this->recalculateAfterPartPayment($fixedDeposit, $remaining, $paidAt);

            $partPayment->setRelation('fixedDeposit', $fixedDeposit);
            FdLedgerPoster::postPartPayment($partPayment);

            return $partPayment;
        });

        return response()->json($partPayment->load(['fixedDeposit' => fn ($q) => $q->with(self::DETAIL_RELATIONS)]), 201);
    }

    /**
     * Undoes a mistaken part payment — reverses the ledger/till and restores the
     * FD's principal/interest figures from the *_before snapshot taken at the
     * time of that part payment. Only ever allowed on an FD's most recent
     * part payment, so that snapshot is guaranteed to still match reality
     * (an earlier one's snapshot could otherwise be stale once a later part
     * payment has recalculated the FD again on top of it).
     */
    public function voidPartPayment(Request $request, FdPartPayment $fdPartPayment)
    {
        $fixedDeposit = $fdPartPayment->fixedDeposit;
        $latest = $fixedDeposit->partPayments()->orderByDesc('id')->first();
        if (!$latest || $latest->id !== $fdPartPayment->id) {
            return response()->json(['message' => 'Void the more recent part payment(s) on this FD first.'], 422);
        }

        DB::transaction(function () use ($fixedDeposit, $fdPartPayment) {
            FdLedgerPoster::reversePartPayment($fdPartPayment);

            $fixedDeposit->update([
                'principal_amount' => $fdPartPayment->principal_before,
                'monthly_interest_amount' => $fdPartPayment->monthly_interest_before,
                'total_interest_amount' => $fdPartPayment->total_interest_before,
                'maturity_amount' => $fdPartPayment->maturity_amount_before,
            ]);
            if ($fixedDeposit->interest_payment_method === 'monthly') {
                $fixedDeposit->interestPayments()->where('status', 'Pending')->update(['amount' => $fdPartPayment->monthly_interest_before]);
            }

            $fdPartPayment->delete();
        });

        return $fixedDeposit->fresh()->load(self::DETAIL_RELATIONS);
    }

    /**
     * Applies a part payment's reduced principal to the FD going forward.
     * Past/paid interest is left exactly as it was; only what's still ahead
     * — remaining monthly installments, or the still-to-accrue slice of a
     * maturity-method FD's interest — is recalculated on the new principal
     * at the product's normal (not premature) rate, same simple-interest
     * 365-day basis used to price the part payment itself.
     */
    private function recalculateAfterPartPayment(FixedDeposit $fixedDeposit, float $newPrincipal, string $asOfDate): void
    {
        $rate = (float) $fixedDeposit->interest_rate_percent;

        if ($fixedDeposit->interest_payment_method === 'monthly') {
            $newMonthly = round($newPrincipal * ($rate / 12 / 100), 2);
            $paidSoFar = (float) $fixedDeposit->interestPayments()->where('status', 'Paid')->sum('paid_amount');
            $remainingCount = $fixedDeposit->interestPayments()->where('status', 'Pending')->count();
            $fixedDeposit->interestPayments()->where('status', 'Pending')->update(['amount' => $newMonthly]);
            $fixedDeposit->update([
                'principal_amount' => $newPrincipal,
                'monthly_interest_amount' => $newMonthly,
                'total_interest_amount' => round($paidSoFar + $newMonthly * $remainingCount, 2),
                'maturity_amount' => $newPrincipal,
            ]);

            return;
        }

        // Interest-at-maturity: what's already silently accrued (on the OLD
        // principal, up to today) stays; only the remaining term's accrual
        // switches to the NEW, reduced principal.
        $oldPrincipal = (float) $fixedDeposit->principal_amount;
        $elapsedDays = Carbon::parse($fixedDeposit->start_date)->diffInDays(Carbon::parse($asOfDate));
        $remainingDays = max(0, Carbon::parse($asOfDate)->diffInDays(Carbon::parse($fixedDeposit->maturity_date), false));
        $accrued = round($oldPrincipal * ($rate / 100) * ($elapsedDays / 365), 2);
        $future = round($newPrincipal * ($rate / 100) * ($remainingDays / 365), 2);

        $fixedDeposit->update([
            'principal_amount' => $newPrincipal,
            'total_interest_amount' => $accrued + $future,
            'maturity_amount' => round($newPrincipal + $accrued + $future, 2),
        ]);
    }

    /** Value the customer chose (only allowed if the product permits a choice) or the product's fixed default. */
    private function resolveInterestMethod(?string $requested, FundingProduct $product): string
    {
        if ($requested === null) {
            return $product->interest_method;
        }
        if ($requested !== $product->interest_method && !$product->allow_method_choice) {
            throw ValidationException::withMessages(['interest_payment_method' => "This funding product does not allow choosing the interest method."]);
        }

        return $requested;
    }

    /** Shared FD-row creation for both a fresh issue (store) and a renewal (renew). */
    private function createFixedDeposit(int $branchId, int $customerId, FundingProduct $product, float $principal, int $termMonths, Carbon $startDate, int $userId, string $interestMethod, ?int $renewedFromId = null, array $autoRenew = []): FixedDeposit
    {
        $maturityDate = $startDate->copy()->addMonthsNoOverflow($termMonths);
        $calc = FdInterestCalculator::calculate($principal, (float) $product->annual_rate_percent, $termMonths, $interestMethod);

        $branch = \App\Models\Branch::find($branchId);
        $fdNumber = NumberGenerator::next('fixed_deposit', [
            'branch_id' => $branchId,
            'branch_code' => $branch->code,
            'funding_product_id' => $product->id,
            'funding_product_code' => $product->code,
            'user_id' => $userId,
        ], $product->reference_format);

        $fd = FixedDeposit::create($autoRenew + [
            'fd_number' => $fdNumber,
            'branch_id' => $branchId,
            'customer_id' => $customerId,
            'funding_product_id' => $product->id,
            'principal_amount' => $principal,
            'interest_rate_percent' => $product->annual_rate_percent,
            'interest_payment_method' => $interestMethod,
            'term_months' => $termMonths,
            'start_date' => $startDate->format('Y-m-d'),
            'maturity_date' => $maturityDate->format('Y-m-d'),
            'monthly_interest_amount' => $calc['monthlyInterestAmount'],
            'total_interest_amount' => $calc['totalInterestAmount'],
            'maturity_amount' => $calc['maturityAmount'],
            'status' => 'Active',
            'renewed_from_id' => $renewedFromId,
            'created_by' => $userId,
        ]);
        NumberGenerator::attach($fdNumber, $fd);

        if ($interestMethod === 'monthly') {
            $rows = FdInterestCalculator::generateSchedule($fd);
            $fd->interestPayments()->insert($rows);
            $fd->update(['next_interest_date' => $rows[0]['due_date'] ?? null]);
        }

        return $fd;
    }

    private function assertAmountWithinProduct(float $amount, FundingProduct $product): void
    {
        if ($amount < (float) $product->min_deposit_amount || ($product->max_deposit_amount !== null && $amount > (float) $product->max_deposit_amount)) {
            $max = $product->max_deposit_amount ?? '∞';
            throw ValidationException::withMessages(['deposit_id' => "Deposit amount must be between {$product->min_deposit_amount} and {$max} for {$product->name}."]);
        }
    }

    private function assertTermAllowed(int $termMonths, FundingProduct $product): void
    {
        $allowed = array_map('intval', $product->allowed_terms_months ?? []);
        if (!in_array($termMonths, $allowed, true) && !$product->allow_custom_term) {
            throw ValidationException::withMessages(['term_months' => "This term is not offered by {$product->name}."]);
        }
    }
}
