<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashFloatSettlement;
use App\Models\FinanceTransaction;
use App\Support\CashFloatService;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;

/**
 * Cashier floats — see App\Support\CashFloatService. A float is a
 * cash_accounts row (account_type = CashierFloat); this screen issues one,
 * tracks what has been spent from it, and settles it.
 */
class CashFloatController extends Controller
{
    /** Active floats with their open settlement period and spend-to-date. */
    public function index(Request $request)
    {
        $floats = CurrentBranch::applyScope(
            CashAccount::query()->where('account_type', 'CashierFloat')->where('status', 'Active')
                ->with(['branch:id,name,code', 'responsibleUser:id,name', 'parent:id,name,code'])
        )->get();

        $ids = $floats->pluck('id');
        $settlements = CashFloatSettlement::whereIn('cash_account_id', $ids)
            ->whereIn('status', ['Open', 'SettlementRequested'])
            ->with('approvalRequest:id,status,required_approvals,requested_by,first_approved_by')
            ->get()->keyBy('cash_account_id');
        $spent = FinanceTransaction::whereIn('source_account_id', $ids)
            ->where('source_account_type', 'cash')->where('txn_type', 'CASH_OUT')->where('status', '!=', 'Reversed')
            ->selectRaw('source_account_id, SUM(amount) s')->groupBy('source_account_id')->pluck('s', 'source_account_id');

        return $floats->map(fn ($f) => [
            'id' => $f->id, 'code' => $f->code, 'name' => $f->name,
            'branch' => $f->branch, 'holder' => $f->responsibleUser, 'home' => $f->parent,
            'current_balance' => round((float) $f->current_balance, 2),
            'spent_total' => round((float) ($spent[$f->id] ?? 0), 2),
            'settlement' => $settlements[$f->id] ?? null,
        ])->values();
    }

    public function settlements(Request $request)
    {
        $q = CashFloatSettlement::with(['floatAccount:id,code,name', 'homeAccount:id,name', 'custodian:id,name', 'settler:id,name'])
            ->orderByDesc('id');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return $q->paginate((int) $request->query('per_page', 50));
    }

    public function issue(Request $request)
    {
        $data = $request->validate([
            'home_cash_account_id' => 'required|exists:cash_accounts,id',
            'custodian_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
        ]);

        $req = CashFloatService::issue(
            (int) $data['home_cash_account_id'], (int) $data['custodian_id'],
            (string) $data['amount'], $request->user()->id, $data['notes'] ?? null
        );

        return response()->json(['message' => 'Float request sent to Finance.', 'financeRequest' => $req->load('branch:id,name')], 201);
    }

    public function requestSettlement(Request $request, CashAccount $cashAccount)
    {
        return CashFloatService::requestSettlement($cashAccount->id, $request->user()->id)
            ->load('approvalRequest:id,status,required_approvals');
    }

    public function settle(Request $request, CashFloatSettlement $cashFloatSettlement)
    {
        $data = $request->validate(['counted_amount' => 'required|numeric|min:0']);

        return CashFloatService::settle($cashFloatSettlement->cash_account_id, (string) $data['counted_amount'], $request->user()->id)
            ->load(['floatAccount:id,code,name', 'homeAccount:id,name', 'settler:id,name']);
    }
}
