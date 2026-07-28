<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\FdDeposit;
use App\Services\NumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FdDepositController extends Controller
{
    private const PAYMENT_METHODS = ['Cash', 'Cheque', 'BankTransfer'];

    private const RELATIONS = ['customer:id,full_name,code,nic_no,phone', 'branch:id,name,code', 'fixedDeposit:id,fd_number', 'creator:id,name'];

    public function index(Request $request)
    {
        $q = FdDeposit::with(self::RELATIONS)->orderByDesc('id');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($customerId = $request->query('customerId')) {
            $q->where('customer_id', $customerId);
        }

        return $q->get();
    }

    public function show(FdDeposit $fdDeposit)
    {
        return $fdDeposit->load(self::RELATIONS);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'Available';

        $deposit = DB::transaction(function () use ($data, $request) {
            $branch = Branch::find($data['branch_id']);
            $data['deposit_number'] = NumberGenerator::next('fd_deposit', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'user_id' => $request->user()->id,
            ]);
            $deposit = FdDeposit::create($data);
            NumberGenerator::attach($data['deposit_number'], $deposit);

            return $deposit;
        });

        return response()->json($deposit->load(self::RELATIONS), 201);
    }

    public function destroy(FdDeposit $fdDeposit)
    {
        if ($fdDeposit->status !== 'Available') {
            return response()->json(['message' => 'This deposit has already been allocated to a Fixed Deposit and cannot be deleted.'], 422);
        }
        $fdDeposit->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'required|exists:customers,id',
            'deposit_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'reference_no' => 'nullable|string|max:64',
            'bank_name' => 'nullable|string|max:128|required_unless:payment_method,Cash',
            'notes' => 'nullable|string',
        ]);
    }
}
