<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FdDeposit;
use App\Models\FdRenewal;
use App\Models\FdWithdrawal;
use App\Models\FixedDeposit;
use App\Models\FundingProduct;
use App\Services\NumberGenerator;
use App\Support\FdCashbookPoster;
use App\Support\FdInterestCalculator;
use App\Support\FdLedgerPoster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FixedDepositController extends Controller
{
    private const PAYMENT_METHODS = ['Cash', 'Bank', 'Cheque'];

    private const INTEREST_METHODS = ['monthly', 'maturity'];

    private const SUMMARY_RELATIONS = ['customer:id,full_name,code,nic_no,phone', 'branch:id,name,code', 'fundingProduct:id,name,code,interest_method'];

    private const DETAIL_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone,email,address',
        'branch:id,name,code',
        'fundingProduct',
        'deposits.bank:id,name',
        'interestPayments.payer:id,name',
        'withdrawal',
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
        if (!$fixedDeposit->is_matured) {
            return response()->json(['message' => 'This FD has not matured yet and cannot be renewed.'], 422);
        }

        $data = $request->validate([
            'term_months' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255',
            'enhancement' => 'nullable|array',
            'enhancement.amount' => 'required_with:enhancement|numeric|min:0.01',
            'enhancement.deposit_date' => 'nullable|date',
            'enhancement.payment_method' => ['required_with:enhancement', Rule::in(self::PAYMENT_METHODS)],
            'enhancement.reference_no' => 'nullable|string|max:64',
            'enhancement.bank_id' => 'nullable|exists:banks,id',
            'enhancement.notes' => 'nullable|string',
        ]);

        $fixedDeposit->loadMissing(['fundingProduct', 'branch']);
        $product = $fixedDeposit->fundingProduct;
        $this->assertTermAllowed((int) $data['term_months'], $product);

        [$newFd, $enhancementDeposit] = DB::transaction(function () use ($fixedDeposit, $data, $product, $request) {
            $enhancementAmount = (float) ($data['enhancement']['amount'] ?? 0);
            $newPrincipal = (float) $fixedDeposit->principal_amount + $enhancementAmount;
            $newStart = Carbon::parse($fixedDeposit->maturity_date);

            $newFd = $this->createFixedDeposit(
                branchId: $fixedDeposit->branch_id,
                customerId: $fixedDeposit->customer_id,
                product: $product,
                principal: $newPrincipal,
                termMonths: (int) $data['term_months'],
                startDate: $newStart,
                userId: $request->user()->id,
                // Carries the method the customer originally chose forward, even
                // if the product's own default has since been changed.
                interestMethod: $fixedDeposit->interest_payment_method,
                renewedFromId: $fixedDeposit->id,
            );

            // Only the enhancement portion is a real cash event — the old FD's
            // principal simply rolls forward into the new FD's principal_amount
            // above, with no cash changing hands (see FdLedgerPoster's docblock).
            $enhancementDeposit = null;
            if ($enhancementAmount > 0) {
                $depositNumber = NumberGenerator::next('fd_deposit', [
                    'branch_id' => $fixedDeposit->branch_id,
                    'branch_code' => $fixedDeposit->branch->code,
                    'user_id' => $request->user()->id,
                ]);
                $enhancementDeposit = FdDeposit::create([
                    'deposit_number' => $depositNumber,
                    'branch_id' => $fixedDeposit->branch_id,
                    'customer_id' => $fixedDeposit->customer_id,
                    'deposit_date' => $data['enhancement']['deposit_date'] ?? now()->format('Y-m-d'),
                    'amount' => $enhancementAmount,
                    'payment_method' => $data['enhancement']['payment_method'],
                    'reference_no' => $data['enhancement']['reference_no'] ?? null,
                    'bank_id' => $data['enhancement']['bank_id'] ?? null,
                    'notes' => $data['enhancement']['notes'] ?? null,
                    // Created already-allocated (unlike FdDepositController::store()'s
                    // normal Available-first flow) since it's funding this new FD
                    // in the same breath it's recorded.
                    'status' => 'Allocated',
                    'fixed_deposit_id' => $newFd->id,
                    'created_by' => $request->user()->id,
                ]);
                NumberGenerator::attach($depositNumber, $enhancementDeposit);

                FdCashbookPoster::postDepositReceived($enhancementDeposit);
                FdLedgerPoster::postDepositReceived($enhancementDeposit);
            }

            $fixedDeposit->update([
                'status' => 'Renewed',
                'renewed_to_id' => $newFd->id,
                'closed_at' => now()->format('Y-m-d'),
            ]);

            FdRenewal::create([
                'old_fixed_deposit_id' => $fixedDeposit->id,
                'new_fixed_deposit_id' => $newFd->id,
                'old_principal' => $fixedDeposit->principal_amount,
                'additional_deposit_amount' => $enhancementAmount,
                'new_principal' => $newPrincipal,
                'additional_deposit_id' => $enhancementDeposit?->id,
                'new_term_months' => $data['term_months'],
                'renewed_at' => now()->format('Y-m-d'),
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            return [$newFd, $enhancementDeposit];
        });

        return response()->json([
            'fixed_deposit' => $newFd->load(self::DETAIL_RELATIONS),
            'enhancement_deposit' => $enhancementDeposit,
        ], 201);
    }

    /** Pays out principal (+ maturity-method interest) and closes the FD. */
    public function withdraw(Request $request, FixedDeposit $fixedDeposit)
    {
        $fixedDeposit->loadMissing('branch');
        if (!$fixedDeposit->is_matured) {
            return response()->json(['message' => 'This FD has not matured yet and cannot be withdrawn.'], 422);
        }
        if ($fixedDeposit->interest_payment_method === 'monthly' && $fixedDeposit->interestPayments()->where('status', 'Pending')->exists()) {
            return response()->json(['message' => 'Settle all due monthly interest payments before closing this FD.'], 422);
        }

        $data = $request->validate([
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'closed_at' => 'nullable|date',
        ]);

        $withdrawal = DB::transaction(function () use ($fixedDeposit, $data, $request) {
            $closedAt = $data['closed_at'] ?? now()->format('Y-m-d');
            $interestAmount = $fixedDeposit->interest_payment_method === 'maturity' ? (float) $fixedDeposit->total_interest_amount : 0.0;
            $principalAmount = (float) $fixedDeposit->principal_amount;

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
                'payment_method' => $data['payment_method'],
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'closed_at' => $closedAt,
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($voucherNo, $withdrawal);

            $fixedDeposit->update(['status' => 'Closed', 'closed_at' => $closedAt]);

            $withdrawal->setRelation('fixedDeposit', $fixedDeposit);
            FdCashbookPoster::postWithdrawal($withdrawal);
            FdLedgerPoster::postWithdrawal($withdrawal);

            return $withdrawal;
        });

        return response()->json($withdrawal->load(['fixedDeposit' => fn ($q) => $q->with(self::DETAIL_RELATIONS)]), 201);
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
    private function createFixedDeposit(int $branchId, int $customerId, FundingProduct $product, float $principal, int $termMonths, Carbon $startDate, int $userId, string $interestMethod, ?int $renewedFromId = null): FixedDeposit
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

        $fd = FixedDeposit::create([
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
