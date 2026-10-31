<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PettyCashFund;
use App\Services\NumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PettyCashFundController extends Controller
{
    public function index(Request $request)
    {
        return PettyCashFund::with(['branch:id,name,code', 'custodian:id,name'])
            ->when($request->query('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $branch = Branch::findOrFail($data['branch_id']);
        $fund = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $branch) {
            $code = NumberGenerator::next('petty_cash_fund', ['branch_code' => $branch->code]);
            $fund = PettyCashFund::create($data + ['code' => $code, 'current_balance' => 0]);
            NumberGenerator::attach($code, $fund);

            return $fund;
        });

        return response()->json($fund->load('branch:id,name,code', 'custodian:id,name'), 201);
    }

    public function update(Request $request, PettyCashFund $pettyCashFund)
    {
        $data = $this->validated($request, $pettyCashFund);
        unset($data['branch_id']); // the fund's ledger leaf is opened for a specific branch — moving it isn't supported
        $pettyCashFund->update($data);

        return $pettyCashFund->load('branch:id,name,code', 'custodian:id,name');
    }

    public function destroy(PettyCashFund $pettyCashFund)
    {
        if ((float) $pettyCashFund->current_balance !== 0.0) {
            return response()->json(['message' => 'This float still holds a balance — replenish it to zero or void its vouchers first.'], 422);
        }
        if ($pettyCashFund->replenishments()->exists() || $pettyCashFund->vouchers()->exists()) {
            return response()->json(['message' => 'This float has transaction history and cannot be deleted.'], 422);
        }
        $pettyCashFund->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?PettyCashFund $pettyCashFund = null): array
    {
        return $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'custodian_id' => 'nullable|exists:users,id',
            'name' => ['required', 'string', 'max:128'],
            'float_limit' => ['required', 'numeric', 'min:0.01', new \App\Rules\Money],
            'status' => [Rule::in(['Active', 'Inactive'])],
        ]);
    }
}
