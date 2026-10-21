<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\Branch;
use App\Models\FdLoanPolicy;
use App\Models\FixedDeposit;
use App\Models\FundingProduct;
use App\Models\Loan;
use App\Models\LoanType;
use App\Services\NumberGenerator;
use App\Support\FdLienService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Loan against FD. The loan itself is an ordinary Pending loan application
 * (same approval, disbursement, schedule and payment flow as any other loan);
 * what's different is that the security is a lien on one of the customer's
 * FDs, and the amount limit and rate come from the FD's fd_loan_policies row
 * instead of being typed in. See FdLienService for the rules.
 */
class FdLoanController extends Controller
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** The customer's FDs with, for each, whether it can be pledged and what it could borrow. */
    public function eligibleFds(Request $request)
    {
        $data = $request->validate(['customer_id' => 'required|exists:customers,id']);

        return FixedDeposit::with('fundingProduct:id,name,code')
            ->where('customer_id', $data['customer_id'])
            ->where('status', 'Active')
            ->orderBy('maturity_date')
            ->get()
            ->map(function (FixedDeposit $fd) {
                $q = FdLienService::quote($fd);
                unset($q['policy']);

                return array_merge($fd->toArray(), ['loan_quote' => $q]);
            });
    }

    public function quote(FixedDeposit $fixedDeposit)
    {
        $q = FdLienService::quote($fixedDeposit);
        unset($q['policy']);

        return $q;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'fixed_deposit_id' => 'required|exists:fixed_deposits,id',
            'customer_bank_detail_id' => 'nullable|exists:customer_bank_details,id',
            'loan_type_id' => 'required|exists:loan_types,id',
            'principal_amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'term_months' => 'required|integer|min:1',
            'installment_frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'purpose' => 'nullable|string|max:255',
            'application_date' => 'nullable|date|before_or_equal:today',
            'loan_officer_id' => 'nullable|exists:users,id',
        ]);

        $fd = FixedDeposit::findOrFail($data['fixed_deposit_id']);
        $loanType = LoanType::findOrFail($data['loan_type_id']);
        if (!$loanType->is_product) {
            throw ValidationException::withMessages(['loan_type_id' => 'This loan type is a category, not a product - pick one of its sub-items.']);
        }
        if ($data['principal_amount'] < $loanType->min_amount || $data['principal_amount'] > $loanType->max_amount) {
            throw ValidationException::withMessages(['principal_amount' => "Amount must be between {$loanType->min_amount} and {$loanType->max_amount} for {$loanType->name}."]);
        }
        if ($data['term_months'] < $loanType->min_term_months || $data['term_months'] > $loanType->max_term_months) {
            throw ValidationException::withMessages(['term_months' => "Term must be between {$loanType->min_term_months} and {$loanType->max_term_months} months for {$loanType->name}."]);
        }

        // Cheap pre-check outside the transaction for clear errors; pledge() repeats it under a row lock.
        $quote = FdLienService::quote($fd);
        if (!$quote['eligible']) {
            throw ValidationException::withMessages(['fixed_deposit_id' => $quote['reason']]);
        }
        if ($quote['max_term_months'] !== null && $data['term_months'] > $quote['max_term_months']) {
            throw ValidationException::withMessages(['term_months' => "Term cannot run past the FD's maturity - at most {$quote['max_term_months']} month(s)."]);
        }
        if ($data['principal_amount'] < $quote['min_loan_amount']) {
            throw ValidationException::withMessages(['principal_amount' => 'Loan amount is below the minimum of ' . number_format($quote['min_loan_amount'], 2) . '.']);
        }

        $loan = DB::transaction(function () use ($data, $fd, $loanType, $quote, $request) {
            $uid = $request->user()->id;
            $branch = Branch::findOrFail($data['branch_id']);
            $code = NumberGenerator::next('loan', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'loan_type_id' => $loanType->id,
                'loan_type_code' => $loanType->code,
                'user_id' => $uid,
            ], $loanType->reference_format);

            $loan = Loan::create([
                'branch_id' => $branch->id,
                'code' => $code,
                'customer_id' => $fd->customer_id,
                // Defaults to the account the FD itself pays out to.
                'customer_bank_detail_id' => \App\Models\CustomerBankDetail::assertBelongsTo((int) ($data['customer_bank_detail_id'] ?? $fd->customer_bank_detail_id), (int) $fd->customer_id),
                'principal_amount' => $data['principal_amount'],
                'interest_rate_percent' => $quote['interest_rate_percent'],
                'rate_period' => $quote['rate_period'],
                'term_months' => $data['term_months'],
                'status' => 'Pending',
                'applied_by' => $uid,
                'created_by' => $uid,
            ]);
            NumberGenerator::attach($code, $loan);
            $loan->loanDetail()->create([
                'loan_type_id' => $loanType->id,
                'down_payment' => 0,
                'interest_method' => $loanType->interest_method,
                'installment_frequency' => $data['installment_frequency'],
                'purpose' => $data['purpose'] ?? "Loan against FD {$fd->fd_number}",
                'application_date' => $data['application_date'] ?? now()->toDateString(),
                'loan_officer_id' => $data['loan_officer_id'] ?? null,
            ]);

            FdLienService::pledge($loan, $fd, $uid);

            $type = ApprovalType::where('code', 'loan_application')->firstOrFail();
            ApprovalRequest::create([
                'approval_type_id' => $type->id,
                'subject_id' => $loan->id,
                'requested_by' => $uid,
                'requested_at' => now(),
                'required_approvals' => $type->required_approvals,
            ]);

            return $loan;
        });

        return response()->json($loan->load(['customer:id,full_name,code', 'loanDetail.loanType:id,name,code', 'collateral.fdDetail.fixedDeposit:id,fd_number,principal_amount,maturity_date,lien_amount']), 201);
    }

    /** Closes the loan's pledged FD and applies the proceeds to the loan (excess to the customer). */
    public function settle(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'excess_payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:255',
            'allow_premature' => 'boolean',
        ]);
        $r = FdLienService::settle($loan, $request->user()->id, $data);

        return [
            'applied_to_loan' => $r['applied'],
            'paid_to_customer' => $r['excess'],
            'remaining_loan_balance' => $r['remaining_balance'],
            'voucher_no' => $r['withdrawal']->voucher_no,
            'payment_code' => $r['payment']->code,
            'loan' => $loan->fresh(),
        ];
    }

    // --- Policies (one per funding product) ---

    public function policies()
    {
        return FundingProduct::orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'code', 'status'])
            ->map(fn ($p) => array_merge($p->toArray(), ['policy' => FdLoanPolicy::where('funding_product_id', $p->id)->first()]));
    }

    public function savePolicy(Request $request, FundingProduct $fundingProduct)
    {
        $data = $request->validate([
            'max_ltv_percent' => 'required|numeric|gt:0|max:100',
            'rate_mode' => ['required', Rule::in(['spread', 'fixed'])],
            'rate_spread_percent' => 'required_if:rate_mode,spread|nullable|numeric|min:0|max:999',
            'fixed_rate_percent' => 'required_if:rate_mode,fixed|nullable|numeric|min:0|max:999',
            'min_loan_amount' => 'nullable|numeric|min:0',
            'max_loan_amount' => 'nullable|numeric|min:0',
            'processing_fee_percent' => 'nullable|numeric|min:0|max:100',
            'min_days_to_maturity' => 'nullable|integer|min:0|max:3650',
            'cap_term_to_maturity' => 'boolean',
            'auto_adjust_on_maturity' => 'boolean',
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
        ]);
        $data['min_loan_amount'] = $data['min_loan_amount'] ?? 0;
        if (isset($data['max_loan_amount']) && $data['max_loan_amount'] < $data['min_loan_amount']) {
            throw ValidationException::withMessages(['max_loan_amount' => 'Maximum must be at least the minimum.']);
        }

        // Nullable columns keep an explicit null (clears a cap); NOT NULL ones fall back to their defaults.
        $data['rate_spread_percent'] = $data['rate_spread_percent'] ?? 0;
        $data['processing_fee_percent'] = $data['processing_fee_percent'] ?? 0;
        $data['min_days_to_maturity'] = $data['min_days_to_maturity'] ?? 30;
        $data['cap_term_to_maturity'] = $data['cap_term_to_maturity'] ?? true;
        $data['auto_adjust_on_maturity'] = $data['auto_adjust_on_maturity'] ?? false;
        $data['status'] = $data['status'] ?? 'Active';
        if ($data['rate_mode'] === 'spread') {
            $data['fixed_rate_percent'] = null;
        }

        return FdLoanPolicy::updateOrCreate(['funding_product_id' => $fundingProduct->id], $data);
    }
}
