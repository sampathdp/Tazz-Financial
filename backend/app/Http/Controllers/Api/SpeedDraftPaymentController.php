<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FacilityPayment;
use App\Models\SpeedDraft;
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
    private const TYPE_CODES = ['Interest' => 'INT', 'CapitalReduction' => 'CAP', 'Settlement' => 'SET'];

    public function index(SpeedDraft $speedDraft)
    {
        return $speedDraft->payments()->with(['receiver:id,name', 'voider:id,name'])->get();
    }

    /** Preview-only: the receipt number the next payment on this draft would get — see NumberGenerator::peek(). */
    public function nextReceiptNo(Request $request, SpeedDraft $speedDraft)
    {
        $data = $request->validate(['payment_type' => ['nullable', Rule::in(['Interest', 'CapitalReduction', 'Settlement'])]]);
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
        if ($speedDraft->status !== 'Active') {
            return response()->json(['message' => 'Payments can only be recorded against an active Speed Draft.'], 422);
        }
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_type' => ['required', Rule::in(['Interest', 'CapitalReduction', 'Settlement'])],
            'payment_method' => ['nullable', Rule::in(['Cash', 'Bank', 'Cheque'])],
            'paid_at' => 'nullable|date',
            'receipt_no' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:255',
        ]);
        $type = $data['payment_type'];

        if ($type === 'Interest') {
            $outstanding = SpeedDraftPaymentAllocator::outstandingInterest($speedDraft);
            if ($outstanding <= 0) {
                return response()->json(['message' => 'There is no outstanding interest to pay on this Speed Draft.'], 422);
            }
            if ($data['amount'] > $outstanding) {
                return response()->json(['message' => "Amount exceeds the outstanding interest ({$outstanding})."], 422);
            }
        } elseif ($type === 'CapitalReduction') {
            $outstanding = (float) $speedDraft->outstanding_balance_cache;
            if ($outstanding <= 0) {
                return response()->json(['message' => 'There is no outstanding capital to reduce on this Speed Draft.'], 422);
            }
            if ($data['amount'] > $outstanding) {
                return response()->json(['message' => "Amount exceeds the outstanding capital ({$outstanding})."], 422);
            }
        } else {
            $outstanding = SpeedDraftPaymentAllocator::outstandingBalance($speedDraft);
            if ($outstanding <= 0) {
                return response()->json(['message' => 'This Speed Draft has no outstanding balance.'], 422);
            }
            // Full settlement always targets the current outstanding balance —
            // computed server-side so a stale client value can't under/overstate it.
            $data['amount'] = $outstanding;
        }

        $payment = DB::transaction(function () use ($speedDraft, $data, $type, $request) {
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
            };

            // Each poster only writes an entry for the bucket it actually finds
            // allocated (see SpeedDraftLedgerPoster) — a Settlement payment
            // typically has both, so both fire; Interest/CapitalReduction only
            // ever populate the matching bucket.
            SpeedDraftLedgerPoster::postInterestReceipt($payment);
            SpeedDraftLedgerPoster::postCapitalReceipt($payment);

            return $payment;
        });

        return response()->json($payment->load('receiver:id,name'), 201);
    }

    public function destroy(Request $request, FacilityPayment $payment)
    {
        if ($payment->status === 'Voided') {
            return response()->json(['message' => 'This payment is already voided.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);
        $payment->load('allocations.schedule');

        DB::transaction(function () use ($payment, $data, $request) {
            $speedDraft = SpeedDraft::findOrFail($payment->credit_facility_id);
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

            if ($capitalReversed > 0) {
                $speedDraft->update(['outstanding_balance_cache' => round((float) $speedDraft->outstanding_balance_cache + $capitalReversed, 2)]);
                SpeedDraftScheduler::recalculateOpenRows($speedDraft->fresh());
            }

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
