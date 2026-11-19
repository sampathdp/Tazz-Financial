<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FacilityCollateralPawnDetail;
use App\Models\FacilityPayment;
use App\Models\Pawning;
use App\Models\PawningArticleType;
use App\Models\PawningRedemption;
use App\Models\PawningRenewal;
use App\Models\PawningType;
use App\Services\NumberGenerator;
use App\Support\PawningInterestCalculator;
use App\Support\PawningLedgerPoster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A pawning ticket is issued and disbursed in one step — store() takes it
 * straight to Active, unlike Loan/SpeedDraft's Pending->Approved->disburse
 * chain. That's not a shortcut: on the branch counter, the appraisal IS the
 * approval — the officer weighs and tests the gold, quotes an amount on the
 * spot, and hands over cash immediately. There is no separate underwriting
 * step to model. See the Pawning model's docblock for why there's also no
 * facility_schedules row: interest is flat/cycle-based (see
 * PawningInterestCalculator), never amortized.
 */
class PawningController extends Controller
{
    /** Payment method -> receipt-number {TYPE} short code — same convention as LoanPaymentController::TYPE_CODES. */
    private const METHOD_CODES = ['Cash' => 'CA', 'Bank' => 'BT', 'Cheque' => 'CQ'];

    private const SUMMARY_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone',
        'branch:id,name,code',
        'pawningDetail.pawningType:id,name,code,normal_rate_percent,special_rate_percent,special_rate_days,cycle_days',
    ];

    private const DETAIL_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone,address',
        'branch:id,name,code',
        'pawningDetail.pawningType',
        'pawningDetail.previousFacility:id,code',
        'collateral.pawnDetail.articleType',
        'collateral.pawnDetail.appraisedBy:id,name',
        'payments.receiver:id,name', 'payments.voider:id,name',
        'renewals.renewedBy:id,name',
        'redemptions.settledBy:id,name', 'redemptions.items',
        'statusEvents.recordedBy:id,name',
        'applier:id,name', 'creator:id,name',
    ];

    public function index(Request $request)
    {
        return Pawning::with(self::SUMMARY_RELATIONS)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get();
    }

    /** Active tickets past repay_due_date + the scheme's grace period — computed live, never a stored flag. */
    public function overdue()
    {
        $today = Carbon::today();

        return Pawning::where('status', 'Active')
            ->with(self::SUMMARY_RELATIONS)
            ->whereHas('pawningDetail.pawningType')
            ->get()
            ->map(function (Pawning $pawning) use ($today) {
                $detail = $pawning->pawningDetail;
                $graceDays = (int) ($detail->pawningType->grace_period_days ?? 5);
                $dueDate = Carbon::parse($detail->repay_due_date);
                $overdueSince = $dueDate->copy()->addDays($graceDays);
                if ($today->lt($overdueSince)) {
                    return null;
                }
                $pawning->setAttribute('daysOverdue', (int) $overdueSince->diffInDays($today));
                $pawning->setAttribute('repayDueDate', $dueDate->toDateString());

                return $pawning;
            })
            ->filter()
            ->values();
    }

    /**
     * Admin-only correction of article descriptions / remarks. Weights, rates and values
     * drive the posted principal, so they are not editable. Only before any payment.
     */
    public function adminUpdate(Request $request, Pawning $pawning)
    {
        if ($pawning->status !== 'Active' || $pawning->payments()->exists() || $pawning->renewals()->exists() || $pawning->redemptions()->exists()) {
            return response()->json(['message' => 'This pawn ticket already has payments or is closed and can no longer be edited.'], 422);
        }

        $data = $request->validate([
            'articles' => 'required|array|min:1',
            'articles.*.id' => 'required|integer',
            'articles.*.description' => 'required|string|max:255',
            'articles.*.remark' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($pawning, $data) {
            foreach ($data['articles'] as $a) {
                $collateral = $pawning->collateral()->findOrFail($a['id']);
                $collateral->update(['description' => $a['description']]);
                $collateral->pawnDetail()->update(['remark' => $a['remark'] ?? null]);
            }
        });

        return $this->show($pawning->fresh());
    }

    public function show(Pawning $pawning)
    {
        $pawning->load(self::DETAIL_RELATIONS);
        $pawning->setAttribute('summary', PawningInterestCalculator::quoteFull($pawning));

        return $pawning;
    }

    public function store(Request $request)
    {
        [$facilityData, $articles, $purpose] = $this->validated($request);
        $branch = Branch::findOrFail($facilityData['branch_id']);
        $type = PawningType::findOrFail($facilityData['pawning_type_id']);

        $pawning = DB::transaction(function () use ($facilityData, $articles, $purpose, $branch, $type, $request) {
            $startDate = Carbon::parse($facilityData['start_date'] ?? now()->toDateString());
            $repayDueDate = $startDate->copy()->addDays($type->cycle_days);

            $code = NumberGenerator::next('pawning', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'user_id' => $request->user()->id,
            ]);

            $pawning = Pawning::create([
                'branch_id' => $branch->id,
                'customer_id' => $facilityData['customer_id'],
                'code' => $code,
                'principal_amount' => $facilityData['principal_amount'],
                'interest_rate_percent' => $type->normal_rate_percent,
                'rate_period' => 'monthly',
                // No day-precision term column on the shared header — this is only
                // ever a coarse month-equivalent to satisfy chk_cf_term; the real
                // cycle length is pawning_types.cycle_days / repay_due_date below.
                'term_months' => max(1, (int) ceil($type->cycle_days / 30)),
                'start_date' => $startDate->toDateString(),
                'maturity_date' => $repayDueDate->toDateString(),
                'disbursed_amount' => $facilityData['principal_amount'],
                'disbursed_at' => now(),
                'outstanding_balance_cache' => $facilityData['principal_amount'],
                'status' => 'Active',
                'applied_by' => $request->user()->id,
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($code, $pawning);

            $totalGross = 0.0;
            $totalNet = 0.0;
            $totalAppraised = 0.0;
            foreach ($articles as $i => $a) {
                $appraisedValue = round($a['net_weight_grams'] * $a['rate_per_gram'], 2);
                $maxLoanValue = round($appraisedValue * $type->ltv_percent / 100, 2);
                $totalGross = round($totalGross + $a['gross_weight_grams'], 3);
                $totalNet = round($totalNet + $a['net_weight_grams'], 3);
                $totalAppraised = round($totalAppraised + $appraisedValue, 2);

                $collateral = $pawning->collateral()->create([
                    'collateral_type' => 'PawnedJewelry',
                    'collateral_category' => null,
                    'description' => $a['description'],
                    'estimated_value' => $appraisedValue,
                ]);
                $collateral->pawnDetail()->create([
                    'item_no' => $i + 1,
                    'article_type_id' => $a['article_type_id'],
                    'declared_karat' => $a['declared_karat'] ?? null,
                    'tested_karat' => $a['tested_karat'],
                    'gross_weight_grams' => $a['gross_weight_grams'],
                    'net_weight_grams' => $a['net_weight_grams'],
                    'rate_per_gram' => $a['rate_per_gram'],
                    'appraised_value' => $appraisedValue,
                    'max_loan_value' => $maxLoanValue,
                    'item_status' => 'Pledged',
                    'appraised_by' => $request->user()->id,
                    'remark' => $a['remark'] ?? null,
                ]);
            }

            $pawning->pawningDetail()->create([
                'pawning_type_id' => $type->id,
                'applied_rate_percent' => $type->normal_rate_percent,
                'is_special_rate' => false,
                'total_gross_weight_grams' => $totalGross,
                'total_net_weight_grams' => $totalNet,
                'total_appraised_value' => $totalAppraised,
                'repay_due_date' => $repayDueDate->toDateString(),
                'renewal_count' => 0,
                'previous_facility_id' => $facilityData['previous_facility_id'] ?? null,
                'purpose' => $purpose,
            ]);

            PawningLedgerPoster::postDisbursement($pawning->fresh(), $request->user()->id);

            return $pawning;
        });

        return response()->json($pawning->load(self::DETAIL_RELATIONS), 201);
    }

    /**
     * Settles the current cycle's flat interest (PawningInterestCalculator)
     * and optionally reduces capital, then rolls repay_due_date forward by
     * another full cycle. capital_payment must stay strictly below the
     * outstanding balance — clearing it entirely is a redemption, not a
     * renewal (use redeem() instead).
     */
    /**
     * Locks the ticket row and refuses if it moved since the caller's pre-transaction read (another renewal,
     * redemption or void). The amounts quoted to the cashier were computed from that read, so they must still hold.
     */
    private function lockTicket(Pawning $pawning, ?float $expectedOutstanding = null, bool $mustBeActive = true): Pawning
    {
        $locked = Pawning::lockForUpdate()->findOrFail($pawning->id);
        $moved = $expectedOutstanding !== null && abs((float) $locked->outstanding_balance_cache - $expectedOutstanding) > 0.005;
        if (($mustBeActive && $locked->status !== 'Active') || $moved) {
            throw \Illuminate\Validation\ValidationException::withMessages(['pawning' => 'This ticket changed while the request was being processed (another renewal, redemption or void). Reload and try again.']);
        }
        $pawning->setRawAttributes($locked->getAttributes(), true);

        return $pawning;
    }

    public function renew(Request $request, Pawning $pawning)
    {
        if ($pawning->status !== 'Active') {
            return response()->json(['message' => 'Only an active pawning ticket can be renewed.'], 422);
        }
        $data = $request->validate([
            'capital_payment' => 'nullable|numeric|min:0',
            'payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            // Bank transfer: the company account it landed in. Left out, the receipt waits in 1030 Undeposited Funds.
            'bank_account_id' => ['nullable', Rule::exists('bank_accounts', 'id')->where('status', 'Active')],
            'paid_at' => 'nullable|date',
            'receipt_no' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:255',
        ]);

        $outstanding = (float) $pawning->outstanding_balance_cache;
        $capitalPaid = round($data['capital_payment'] ?? 0, 2);
        if ($capitalPaid >= $outstanding) {
            return response()->json(['message' => 'A capital payment that clears the full balance is a redemption, not a renewal — use Redeem instead.'], 422);
        }
        $asOf = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
        $quote = PawningInterestCalculator::quoteFull($pawning, $asOf);
        $amount = round($quote['interest'] + $capitalPaid, 2);
        if ($amount <= 0) {
            return response()->json(['message' => 'Nothing to collect for this renewal.'], 422);
        }

        $pawning = DB::transaction(function () use ($pawning, $data, $capitalPaid, $quote, $amount, $outstanding, $asOf, $request) {
            $this->lockTicket($pawning, $outstanding);
            $detail = $pawning->pawningDetail()->with('pawningType')->firstOrFail();
            $branch = Branch::findOrFail($pawning->branch_id);
            $method = $data['payment_method'] ?? 'Cash';

            $code = NumberGenerator::next('payment', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'type_code' => self::METHOD_CODES[$method] ?? 'CA',
                'user_id' => $request->user()->id,
            ]);
            $payment = FacilityPayment::create([
                'code' => $code,
                'credit_facility_id' => $pawning->id,
                'branch_id' => $pawning->branch_id,
                'amount' => $amount,
                'payment_type' => $capitalPaid > 0 ? 'Regular' : 'Interest',
                'payment_method' => $method,
                'bank_account_id' => $method === 'Bank' ? ($data['bank_account_id'] ?? null) : null,
                'received_by' => $request->user()->id,
                'paid_at' => $data['paid_at'] ?? now(),
                'receipt_no' => $data['receipt_no'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            NumberGenerator::attach($code, $payment);
            $payment->allocations()->create([
                'principal_applied' => $capitalPaid,
                'interest_applied' => $quote['interest'],
                'penalty_applied' => 0,
            ]);

            $newDueDate = $asOf->copy()->addDays($detail->pawningType->cycle_days);
            $newOutstanding = round($outstanding - $capitalPaid, 2);
            $renewalNo = $detail->renewal_count + 1;

            PawningRenewal::create([
                'credit_facility_id' => $pawning->id,
                'renewal_no' => $renewalNo,
                'old_due_date' => $detail->repay_due_date,
                'new_due_date' => $newDueDate->toDateString(),
                'capital_before' => $outstanding,
                'interest_settled' => $quote['interest'],
                'capital_after' => $newOutstanding,
                'applied_rate_percent' => $quote['rate'],
                'facility_payment_id' => $payment->id,
                'renewed_by' => $request->user()->id,
                'renewed_at' => $data['paid_at'] ?? now(),
                'remark' => $data['notes'] ?? null,
            ]);

            $detail->update([
                'applied_rate_percent' => $quote['rate'],
                'is_special_rate' => $quote['isSpecial'],
                'repay_due_date' => $newDueDate->toDateString(),
                'renewal_count' => $renewalNo,
            ]);
            $pawning->update([
                'outstanding_balance_cache' => $newOutstanding,
                'maturity_date' => $newDueDate->toDateString(),
            ]);

            PawningLedgerPoster::postCashReceipt($payment);

            return $pawning;
        });

        return $pawning->load(self::DETAIL_RELATIONS);
    }

    /**
     * Full redemption settles the whole outstanding balance and releases
     * every pledged article. Partial redemption releases only the given
     * articles, charging a share of the outstanding capital proportional
     * to those articles' share of the ticket's total max_loan_value — the
     * simplest defensible split when several articles secure one shared
     * balance. Interest is quoted (PawningInterestCalculator) on whatever
     * capital this redemption actually settles, at today's cycle position.
     */
    public function redeem(Request $request, Pawning $pawning)
    {
        if ($pawning->status !== 'Active') {
            return response()->json(['message' => 'Only an active pawning ticket can be redeemed.'], 422);
        }
        $data = $request->validate([
            'redemption_type' => ['required', Rule::in(['Full', 'Partial'])],
            'facility_collateral_ids' => 'required_if:redemption_type,Partial|array|min:1',
            'facility_collateral_ids.*' => 'integer|exists:facility_collateral,id',
            'payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            // Bank transfer: the company account it landed in. Left out, the receipt waits in 1030 Undeposited Funds.
            'bank_account_id' => ['nullable', Rule::exists('bank_accounts', 'id')->where('status', 'Active')],
            'paid_at' => 'nullable|date',
            'receipt_no' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:255',
        ]);

        $pledged = $pawning->collateral()->where('collateral_type', 'PawnedJewelry')
            ->whereHas('pawnDetail', fn ($q) => $q->where('item_status', 'Pledged'))
            ->with('pawnDetail')->get();
        if ($pledged->isEmpty()) {
            return response()->json(['message' => 'No pledged articles remain on this ticket.'], 422);
        }

        $outstanding = (float) $pawning->outstanding_balance_cache;
        if ($data['redemption_type'] === 'Full') {
            $releaseIds = $pledged->pluck('id')->all();
        } else {
            $releaseIds = $data['facility_collateral_ids'];
            if ($pledged->whereIn('id', $releaseIds)->count() !== count($releaseIds)) {
                return response()->json(['message' => 'One or more selected articles are not pledged on this ticket.'], 422);
            }
        }

        $asOf = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
        $quote = PawningInterestCalculator::quoteForArticles($pawning, $releaseIds, $asOf);
        $capitalSettled = $quote['capital'];
        $interestSettled = $quote['interest'];
        $totalSettled = $quote['total'];
        if ($totalSettled <= 0) {
            return response()->json(['message' => 'Nothing to settle for this redemption.'], 422);
        }

        $pawning = DB::transaction(function () use ($pawning, $data, $releaseIds, $capitalSettled, $interestSettled, $totalSettled, $pledged, $outstanding, $request) {
            $this->lockTicket($pawning, $outstanding);
            $stillPledged = FacilityCollateralPawnDetail::whereIn('facility_collateral_id', $releaseIds)->where('item_status', 'Pledged')->lockForUpdate()->count();
            if ($stillPledged !== count($releaseIds)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['facility_collateral_ids' => 'One or more selected articles are no longer pledged on this ticket.']);
            }
            $branch = Branch::findOrFail($pawning->branch_id);
            $method = $data['payment_method'] ?? 'Cash';

            $code = NumberGenerator::next('payment', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'type_code' => self::METHOD_CODES[$method] ?? 'CA',
                'user_id' => $request->user()->id,
            ]);
            $payment = FacilityPayment::create([
                'code' => $code,
                'credit_facility_id' => $pawning->id,
                'branch_id' => $pawning->branch_id,
                'amount' => $totalSettled,
                'payment_type' => 'Settlement',
                'payment_method' => $method,
                'bank_account_id' => $method === 'Bank' ? ($data['bank_account_id'] ?? null) : null,
                'received_by' => $request->user()->id,
                'paid_at' => $data['paid_at'] ?? now(),
                'receipt_no' => $data['receipt_no'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            NumberGenerator::attach($code, $payment);
            $payment->allocations()->create([
                'principal_applied' => $capitalSettled,
                'interest_applied' => $interestSettled,
                'penalty_applied' => 0,
            ]);

            $redemption = PawningRedemption::create([
                'credit_facility_id' => $pawning->id,
                'redemption_type' => $data['redemption_type'],
                'capital_settled' => $capitalSettled,
                'interest_settled' => $interestSettled,
                'total_settled' => $totalSettled,
                'receipt_no' => $data['receipt_no'] ?? null,
                'facility_payment_id' => $payment->id,
                'settled_by' => $request->user()->id,
                'settled_at' => $data['paid_at'] ?? now(),
            ]);
            $redemption->items()->attach($releaseIds);
            FacilityCollateralPawnDetail::whereIn('facility_collateral_id', $releaseIds)->update(['item_status' => 'Released']);

            $newOutstanding = max(0, round($outstanding - $capitalSettled, 2));
            $remainingPledged = $pledged->whereNotIn('id', $releaseIds)->count();
            $pawning->update(['outstanding_balance_cache' => $newOutstanding]);
            if ($remainingPledged === 0 || $newOutstanding <= 0.01) {
                $pawning->update(['status' => 'Closed', 'closed_at' => now(), 'outstanding_balance_cache' => 0]);
            }

            PawningLedgerPoster::postCashReceipt($payment);

            return $pawning;
        });

        return $pawning->load(self::DETAIL_RELATIONS);
    }

    /**
     * Voids a renewal or redemption payment and unwinds its business effect —
     * only the most recent Active payment on the ticket may be voided, since
     * every later renewal/redemption's math (capital_before, item_status)
     * assumes this one already happened; voiding out of order would corrupt
     * that chain. applied_rate_percent/is_special_rate on pawning_facility_
     * details are display-only snapshots (PawningInterestCalculator always
     * recomputes the live rate from repay_due_date, never reads them), so
     * only repay_due_date/renewal_count/outstanding_balance_cache/item_status
     * actually need restoring — the same distinction PawningLedgerPoster's
     * reverseAllFor draws for the GL side.
     */
    public function voidPayment(Request $request, FacilityPayment $payment)
    {
        $pawning = Pawning::find($payment->credit_facility_id);
        if (!$pawning) {
            abort(404);
        }
        if ($payment->status !== 'Active') {
            return response()->json(['message' => 'This payment is already voided.'], 422);
        }
        $latest = $pawning->payments()->where('status', 'Active')->orderByDesc('paid_at')->orderByDesc('id')->first();
        if (!$latest || $latest->id !== $payment->id) {
            return response()->json(['message' => 'Only the most recent payment on this ticket can be voided — void that one first.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);

        $pawning = DB::transaction(function () use ($pawning, $payment, $data, $request) {
            $this->lockTicket($pawning, null, false);
            $livePayment = FacilityPayment::lockForUpdate()->findOrFail($payment->id);
            $latestNow = $pawning->payments()->where('status', 'Active')->orderByDesc('paid_at')->orderByDesc('id')->first();
            if ($livePayment->status !== 'Active' || !$latestNow || $latestNow->id !== $payment->id) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payment' => 'This payment was already voided or is no longer the most recent on the ticket.']);
            }
            $renewal = PawningRenewal::where('facility_payment_id', $payment->id)->first();
            $redemption = $renewal ? null : PawningRedemption::where('facility_payment_id', $payment->id)->first();

            if ($renewal) {
                $pawning->pawningDetail()->update([
                    'repay_due_date' => $renewal->old_due_date,
                    'renewal_count' => max(0, $renewal->renewal_no - 1),
                ]);
                $pawning->update(['outstanding_balance_cache' => $renewal->capital_before]);
                $renewal->markVoided($request->user()->id, $data['void_reason'] ?? 'Payment voided');
            } elseif ($redemption) {
                $releasedIds = $redemption->items()->pluck('id');
                FacilityCollateralPawnDetail::whereIn('facility_collateral_id', $releasedIds)->update(['item_status' => 'Pledged']);
                $restoredOutstanding = round((float) $pawning->outstanding_balance_cache + (float) $redemption->capital_settled, 2);
                $pawning->update(['outstanding_balance_cache' => $restoredOutstanding]);
                // A void reopens a ticket that had auto-closed on full/last redemption — same convention as SpeedDraftPaymentController::destroy().
                if ($pawning->status === 'Closed') {
                    $pawning->update(['status' => 'Active', 'closed_at' => null]);
                }
                $redemption->markVoided($request->user()->id, $data['void_reason'] ?? 'Payment voided');
            } else {
                abort(422, 'Cannot determine what this payment settled — nothing to void.');
            }

            $payment->update([
                'status' => 'Voided',
                'void_reason' => $data['void_reason'] ?? null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);
            PawningLedgerPoster::reverseAllFor($payment, $request->user()->id);

            return $pawning->fresh();
        });

        return $pawning->load(self::DETAIL_RELATIONS);
    }

    /** @return array{0: array, 1: array, 2: ?string} [facility fields, articles, purpose] */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'required|exists:customers,id',
            'pawning_type_id' => 'required|exists:pawning_types,id',
            'principal_amount' => ['required', 'numeric', 'min:1', new \App\Rules\Money],
            'start_date' => 'nullable|date',
            'purpose' => 'nullable|string|max:128',
            'previous_facility_id' => 'nullable|integer|exists:credit_facilities,id',
            'articles' => 'required|array|min:1',
            'articles.*.article_type_id' => 'required|exists:pawning_article_types,id',
            'articles.*.description' => 'nullable|string|max:255',
            'articles.*.declared_karat' => 'nullable|numeric|min:0|max:99.99',
            'articles.*.tested_karat' => 'required|numeric|min:0.01|max:99.99',
            'articles.*.gross_weight_grams' => 'required|numeric|min:0.001',
            'articles.*.net_weight_grams' => 'required|numeric|min:0.001',
            'articles.*.rate_per_gram' => 'required|numeric|min:0.01',
            'articles.*.remark' => 'nullable|string|max:255',
        ]);

        $type = PawningType::findOrFail($validated['pawning_type_id']);
        if ($type->status !== 'Active') {
            throw ValidationException::withMessages(['pawning_type_id' => 'This pawning scheme is not active.']);
        }

        $articleTypeIds = array_unique(array_column($validated['articles'], 'article_type_id'));
        $articleTypeNames = PawningArticleType::whereIn('id', $articleTypeIds)->pluck('name', 'id');

        $totalMaxLoanValue = 0.0;
        $articles = [];
        foreach ($validated['articles'] as $a) {
            if ($a['net_weight_grams'] > $a['gross_weight_grams']) {
                throw ValidationException::withMessages(['articles' => 'Net weight cannot exceed gross weight for an article.']);
            }
            $appraisedValue = round($a['net_weight_grams'] * $a['rate_per_gram'], 2);
            $totalMaxLoanValue = round($totalMaxLoanValue + $appraisedValue * $type->ltv_percent / 100, 2);
            $articles[] = array_merge($a, [
                'description' => $a['description'] ?? sprintf(
                    '%s - %sg %skt',
                    $articleTypeNames[$a['article_type_id']] ?? 'Article',
                    $a['net_weight_grams'],
                    $a['tested_karat']
                ),
            ]);
        }

        if ($validated['principal_amount'] > $totalMaxLoanValue) {
            throw ValidationException::withMessages(['principal_amount' => "Amount exceeds the maximum advance for these articles ({$totalMaxLoanValue})."]);
        }

        $facilityData = [
            'branch_id' => $validated['branch_id'],
            'customer_id' => $validated['customer_id'],
            'pawning_type_id' => $validated['pawning_type_id'],
            'principal_amount' => $validated['principal_amount'],
            'start_date' => $validated['start_date'] ?? null,
            'previous_facility_id' => $validated['previous_facility_id'] ?? null,
        ];

        return [$facilityData, $articles, $validated['purpose'] ?? null];
    }
}
