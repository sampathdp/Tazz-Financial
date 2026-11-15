<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FacilityPayment;
use App\Models\FacilityPostDatedCheque;
use App\Models\SpeedDraft;
use App\Services\NumberGenerator;
use App\Support\SpeedDraftLedgerPoster;
use App\Support\SpeedDraftPaymentAllocator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Post-dated cheques held against a Speed Draft. Same shared facility_post_dated_cheques
 * table and Pending/Handed/Realized/Bounced/Returned workflow as Loan's; the one
 * difference is realizing - a speed draft payment has a type (Interest / Capital /
 * Charges), so the caller says which one the cheque settles.
 */
class SpeedDraftPostDatedChequeController extends Controller
{
    private const STATUSES = ['Pending', 'Handed', 'Realized', 'Bounced', 'Returned'];

    public function index(SpeedDraft $speedDraft)
    {
        return $speedDraft->postDatedCheques()->with(['creator:id,name', 'bank:id,name'])->get();
    }

    public function store(Request $request, SpeedDraft $speedDraft)
    {
        $data = $request->validate([
            'cheque_no' => 'required|string|max:20',
            'bank_id' => 'required|exists:banks,id',
            'cheque_date' => 'required|date',
            'amount' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'notes' => 'nullable|string|max:255',
        ]);

        $cheque = $speedDraft->postDatedCheques()->create([
            'branch_id' => $speedDraft->branch_id,
            'cheque_no' => $data['cheque_no'],
            'bank_id' => $data['bank_id'],
            'cheque_date' => $data['cheque_date'],
            'amount' => $data['amount'],
            'status' => 'Pending',
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($cheque->load(['creator:id,name', 'bank:id,name']), 201);
    }

    public function updateStatus(Request $request, FacilityPostDatedCheque $cheque)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'notes' => 'nullable|string|max:255',
            'realized_at' => 'required_if:status,Realized|nullable|date',
            'payment_type' => ['required_if:status,Realized', 'nullable', Rule::in(['Interest', 'CapitalReduction', 'Charges'])],
        ]);

        $speedDraft = SpeedDraft::findOrFail($cheque->credit_facility_id);

        if ($data['status'] === 'Realized') {
            if ($cheque->status === 'Realized') {
                return response()->json(['message' => 'This cheque has already been realized.'], 422);
            }
            if ($speedDraft->status !== 'Active') {
                return response()->json(['message' => 'Cheques can only be realized against an active Speed Draft.'], 422);
            }
            $asOf = Carbon::parse($data['realized_at']);
            $type = $data['payment_type'];
            $payments = new SpeedDraftPaymentController();
            $check = $payments->amountCheck($speedDraft, $type, (float) $cheque->amount, $asOf);
            if (isset($check['error'])) {
                return response()->json(['message' => $check['error']], 422);
            }

            DB::transaction(function () use ($speedDraft, $cheque, $asOf, $data, $type, $request, $payments) {
                $speedDraft = SpeedDraft::whereKey($speedDraft->id)->lockForUpdate()->firstOrFail();
                if (FacilityPostDatedCheque::lockForUpdate()->findOrFail($cheque->id)->status === 'Realized') {
                    throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'This cheque has already been realized.']);
                }
                $recheck = $payments->amountCheck($speedDraft, $type, (float) $cheque->amount, $asOf);
                if (isset($recheck['error'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['amount' => $recheck['error']]);
                }
                $branch = Branch::find($speedDraft->branch_id);
                $code = NumberGenerator::next('speed_draft_payment', [
                    'branch_id' => $branch->id,
                    'branch_code' => $branch->code,
                    'type_code' => SpeedDraftPaymentController::TYPE_CODES[$type] ?? 'INT',
                    'user_id' => $request->user()->id,
                ]);
                $payment = FacilityPayment::create([
                    'code' => $code,
                    'credit_facility_id' => $speedDraft->id,
                    'branch_id' => $speedDraft->branch_id,
                    'amount' => $cheque->amount,
                    'payment_method' => 'Cheque',
                    'payment_type' => $type,
                    'clearance_status' => 'Realized',
                    'received_by' => $request->user()->id,
                    'paid_at' => $asOf,
                    'receipt_no' => $cheque->cheque_no,
                    'bank_id' => $cheque->bank_id,
                    'cheque_date' => $cheque->cheque_date,
                    'realized_at' => $asOf,
                    'notes' => $data['notes'] ?? $cheque->notes,
                    'approval_status' => 'Approved',
                ]);
                NumberGenerator::attach($code, $payment);

                match ($type) {
                    'Interest' => SpeedDraftPaymentAllocator::applyInterestPayment($speedDraft, $payment, (float) $cheque->amount, $asOf),
                    'CapitalReduction' => SpeedDraftPaymentAllocator::applyCapitalReduction($speedDraft, $payment, (float) $cheque->amount, $asOf),
                    'Charges' => SpeedDraftPaymentAllocator::applyChargePayment($speedDraft, $payment, (float) $cheque->amount),
                };
                SpeedDraftLedgerPoster::postCashReceipt($payment);

                $cheque->update([
                    'status' => 'Realized',
                    'realized_at' => $asOf,
                    'facility_payment_id' => $payment->id,
                    'notes' => $data['notes'] ?? $cheque->notes,
                ]);
            });
        } elseif ($cheque->status === 'Realized') {
            return response()->json(['message' => 'This cheque has already been realized and applied to the Speed Draft - void the linked payment to reverse it.'], 422);
        } else {
            DB::transaction(function () use ($cheque, $data) {
                $live = FacilityPostDatedCheque::lockForUpdate()->findOrFail($cheque->id);
                if ($live->status === 'Realized') {
                    throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'This cheque was just realized and applied to the Speed Draft.']);
                }
                $cheque->update([
                    'status' => $data['status'],
                    'handed_at' => $data['status'] === 'Handed' ? now() : $live->handed_at,
                    'notes' => $data['notes'] ?? $live->notes,
                ]);
            });
        }

        return $cheque->fresh()->load(['creator:id,name', 'bank:id,name']);
    }

    public function destroy(FacilityPostDatedCheque $cheque)
    {
        if ($cheque->status === 'Realized') {
            return response()->json(['message' => 'This cheque has already been realized - void the linked payment instead of deleting the cheque record.'], 422);
        }
        $cheque->delete();

        return response()->noContent();
    }
}
