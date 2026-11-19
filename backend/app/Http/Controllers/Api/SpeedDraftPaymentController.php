<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FacilityPayment;
use App\Models\SpeedDraft;
use App\Models\SpeedDraftRenewal;
use App\Services\NumberGenerator;
use App\Support\SpeedDraftLedgerPoster;
use App\Support\SpeedDraftPaymentAllocator;
use App\Support\SpeedDraftScheduler;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SpeedDraftPaymentController extends Controller
{
    /** Short code used in the receipt number's {TYPE} segment — see NumberGenerator's 'speed_draft_payment' format. */
    public const TYPE_CODES = ['Interest' => 'INT', 'CapitalReduction' => 'CAP', 'Settlement' => 'SET', 'Charges' => 'CHG'];

    public function index(SpeedDraft $speedDraft)
    {
        return $speedDraft->payments()->with(['receiver:id,name', 'voider:id,name'])->get();
    }

    /** Preview-only: the receipt number the next payment on this draft would get — see NumberGenerator::peek(). */
    public function nextReceiptNo(Request $request, SpeedDraft $speedDraft)
    {
        $data = $request->validate(['payment_type' => ['nullable', Rule::in(['Interest', 'CapitalReduction', 'Settlement', 'Charges'])]]);
        $branch = Branch::find($speedDraft->branch_id);
        $type = $data['payment_type'] ?? 'Interest';
        $code = NumberGenerator::peek('speed_draft_payment', [
            'branch_code' => $branch->code,
            'type_code' => self::TYPE_CODES[$type] ?? 'INT',
        ]);

        return response()->json(['code' => $code]);
    }

    public function store(Request $request, SpeedDraft $speedDraft)
    {
        // Charges (e.g. the document charge) can also be collected on an Approved draft, ahead of disbursement.
        $isChargePayment = $request->input('payment_type') === 'Charges';
        if (!($speedDraft->status === 'Active' || ($isChargePayment && $speedDraft->status === 'Approved'))) {
            return response()->json(['message' => 'Payments can only be recorded against an active Speed Draft (charges also against an approved one).'], 422);
        }
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'payment_type' => ['required', Rule::in(['Interest', 'CapitalReduction', 'Settlement', 'Charges'])],
            'payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            // Bank transfer: the company account it landed in. Left out, the receipt waits in 1030 Undeposited Funds.
            'bank_account_id' => ['nullable', Rule::exists('bank_accounts', 'id')->where('status', 'Active')],
            'paid_at' => 'nullable|date',
            'receipt_no' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:255',
        ]);
        $type = $data['payment_type'];
        $asOf = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : Carbon::today();

        // A receipt can't be dated in the future, nor before the draft was disbursed (or created, if it never was).
        if (isset($data['paid_at'])) {
            $floor = ($speedDraft->disbursed_at ?? $speedDraft->created_at)?->copy()->startOfDay();
            if ($asOf->copy()->startOfDay()->gt(Carbon::today())) {
                return response()->json(['message' => 'The payment date cannot be in the future.'], 422);
            }
            if ($floor && $asOf->copy()->startOfDay()->lt($floor)) {
                return response()->json(['message' => 'The payment date cannot be before ' . $floor->toDateString() . '.'], 422);
            }
        }

        $check = $this->amountCheck($speedDraft, $type, (float) $data['amount'], $asOf);
        if (isset($check['error'])) {
            return response()->json(['message' => $check['error']], 422);
        }
        $data['amount'] = $check['amount'];

        $payment = DB::transaction(function () use ($speedDraft, $data, $type, $request, $asOf) {
            // Serialize payments on one draft, then re-check against the state we now hold the lock on -
            // two simultaneous receipts would otherwise both be sized against the same unpaid balance.
            $speedDraft = SpeedDraft::whereKey($speedDraft->id)->lockForUpdate()->firstOrFail();
            $recheck = $this->amountCheck($speedDraft, $type, (float) $data['amount'], $asOf);
            if (isset($recheck['error'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => $recheck['error']]);
            }
            $branch = Branch::find($speedDraft->branch_id);
            $method = $data['payment_method'] ?? 'Cash';
            $code = NumberGenerator::next('speed_draft_payment', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'type_code' => self::TYPE_CODES[$type] ?? 'INT',
                'user_id' => $request->user()->id,
            ]);
            $payment = FacilityPayment::create([
                'code' => $code,
                'credit_facility_id' => $speedDraft->id,
                'branch_id' => $speedDraft->branch_id,
                'amount' => $data['amount'],
                'payment_type' => $type,
                'payment_method' => $method,
                'bank_account_id' => $method === 'Bank' ? ($data['bank_account_id'] ?? null) : null,
                'received_by' => $request->user()->id,
                'paid_at' => $data['paid_at'] ?? now(),
                'receipt_no' => $data['receipt_no'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            NumberGenerator::attach($code, $payment);

            $asOf = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null;
            match ($type) {
                'Interest' => SpeedDraftPaymentAllocator::applyInterestPayment($speedDraft, $payment, (float) $data['amount'], $asOf),
                'CapitalReduction' => SpeedDraftPaymentAllocator::applyCapitalReduction($speedDraft, $payment, (float) $data['amount'], $asOf),
                'Settlement' => SpeedDraftPaymentAllocator::applySettlement($speedDraft, $payment, $asOf),
                'Charges' => SpeedDraftPaymentAllocator::applyChargePayment($speedDraft, $payment, (float) $data['amount']),
            };

            // Books the interest / penalty / capital split of this cash payment
            // as one COLLECTION transaction (till balance + GL + cashbook).
            SpeedDraftLedgerPoster::postCashReceipt($payment);

            return $payment;
        });

        return response()->json($payment->load('receiver:id,name'), 201);
    }

    /** The amount rules per payment type, shared by the pre-check and the locked re-check. @return array{amount?: float, error?: string} */
    public function amountCheck(SpeedDraft $speedDraft, string $type, float $amount, Carbon $asOf): array
    {
        if ($type === 'Interest') {
            $outstanding = round(SpeedDraftPaymentAllocator::outstandingInterest($speedDraft) + SpeedDraftPaymentAllocator::outstandingPenalty($speedDraft, $asOf), 2);
            if ($outstanding <= 0) {
                return ['error' => 'There is no outstanding interest or penalty to pay on this Speed Draft.'];
            }
            if ($amount > $outstanding + 0.005) {
                return ['error' => "Amount exceeds the outstanding interest and penalty ({$outstanding})."];
            }

            return ['amount' => $amount];
        }
        if ($type === 'Charges') {
            $outstanding = SpeedDraftPaymentAllocator::outstandingCharges($speedDraft);
            if ($outstanding <= 0) {
                return ['error' => 'There are no outstanding charges on this Speed Draft.'];
            }
            if ($amount > $outstanding + 0.005) {
                return ['error' => "Amount exceeds the outstanding charges ({$outstanding})."];
            }

            return ['amount' => $amount];
        }
        if ($type === 'CapitalReduction') {
            $outstandingInterest = round(SpeedDraftPaymentAllocator::outstandingInterest($speedDraft) + SpeedDraftPaymentAllocator::outstandingPenalty($speedDraft, $asOf), 2);
            if ($outstandingInterest > 0) {
                return ['error' => "Settle the outstanding interest and penalty ({$outstandingInterest}) before making a capital payment."];
            }
            $outstanding = (float) $speedDraft->outstanding_balance_cache;
            if ($outstanding <= 0) {
                return ['error' => 'There is no outstanding capital to reduce on this Speed Draft.'];
            }
            if ($amount > $outstanding + 0.005) {
                return ['error' => "Amount exceeds the outstanding capital ({$outstanding})."];
            }

            return ['amount' => $amount];
        }
        $outstanding = SpeedDraftPaymentAllocator::outstandingBalance($speedDraft, $asOf);
        if ($outstanding <= 0) {
            return ['error' => 'This Speed Draft has no outstanding balance.'];
        }

        // Full settlement always targets the current outstanding balance - computed server-side so a stale client value can't under/overstate it.
        return ['amount' => $outstanding];
    }

    public function destroy(Request $request, FacilityPayment $payment)
    {
        if ($payment->status === 'Voided') {
            return response()->json(['message' => 'This payment is already voided.'], 422);
        }
        // A renewal payment belongs to the OLD (now Closed) draft, but the money
        // it recorded is what sized the NEW draft's principal (see
        // SpeedDraftController::renew()). Voiding it the normal way would reopen
        // the old draft while the new one silently keeps assuming that money was
        // collected — a real double-booking, not just a display glitch — so it's
        // blocked here rather than left reachable with no reversal flow behind it.
        if (SpeedDraftRenewal::where('facility_payment_id', $payment->id)->exists()) {
            return response()->json(['message' => 'This payment was part of a Speed Draft renewal and cannot be voided on its own — reversing a renewal isn\'t supported yet.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);
        $payment->load('allocations.schedule', 'chargeAllocations.charge');

        DB::transaction(function () use ($payment, $data, $request) {
            // Lock the draft and the payment: two simultaneous voids must give the capital back once.
            $speedDraft = SpeedDraft::lockForUpdate()->findOrFail($payment->credit_facility_id);
            if (FacilityPayment::lockForUpdate()->findOrFail($payment->id)->status === 'Voided') {
                throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'This payment is already voided.']);
            }
            $capitalReversed = 0.0;

            foreach ($payment->allocations as $alloc) {
                if ((float) $alloc->interest_applied > 0 && $alloc->schedule) {
                    $row = $alloc->schedule;
                    $newPaid = max(0, round((float) $row->paid_amount - $alloc->interest_applied, 2));
                    $row->update([
                        'paid_amount' => $newPaid,
                        'status' => $newPaid <= 0 ? 'Pending' : ($newPaid >= (float) $row->interest_due ? 'Paid' : 'Partial'),
                        'paid_at' => $newPaid >= (float) $row->interest_due ? $row->paid_at : null,
                    ]);
                }
                if ((float) $alloc->principal_applied > 0) {
                    $capitalReversed = round($capitalReversed + (float) $alloc->principal_applied, 2);
                }
            }

            foreach ($payment->chargeAllocations as $ca) {
                if ($ca->charge) {
                    $ca->charge->update(['paid_amount' => max(0, round((float) $ca->charge->paid_amount - (float) $ca->amount_applied, 2))]);
                }
            }

            if ($capitalReversed > 0) {
                $speedDraft->update(['outstanding_balance_cache' => round((float) $speedDraft->outstanding_balance_cache + $capitalReversed, 2)]);
                SpeedDraftScheduler::recalculateOpenRows($speedDraft->fresh());
            }

            \App\Support\SpeedDraftWaivers::syncRowStatuses($speedDraft->fresh());

            // A void reopens a draft that had auto-closed on full payoff — same
            // convention as LoanPaymentController::destroy().
            if ($speedDraft->status === 'Closed') {
                $speedDraft->update(['status' => 'Active', 'closed_at' => null]);
            }

            // Kept as a voided row (not deleted) so it still appears in history.
            $payment->update([
                'status' => 'Voided',
                'void_reason' => $data['void_reason'] ?? null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);

            SpeedDraftLedgerPoster::reverseAllFor($payment, $request->user()->id);
        });

        return response()->noContent();
    }
}
