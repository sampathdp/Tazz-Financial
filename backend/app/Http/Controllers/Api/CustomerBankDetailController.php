<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerBankDetail;
use Illuminate\Http\Request;

class CustomerBankDetailController extends Controller
{
    public function index(Customer $customer)
    {
        return $customer->bankDetails()->with('bank:id,name')->get();
    }

    public function store(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'bank_id' => 'required|exists:banks,id',
            'account_holder' => 'required|string|max:150',
            'bank_branch' => 'nullable|string|max:150',
            'account_number' => ['required', 'string', 'max:34', 'regex:/^[A-Za-z0-9\- ]+$/'],
        ]);

        $detail = $customer->bankDetails()->create($data + ['created_by' => $request->user()->id]);

        return response()->json($detail->load('bank:id,name'), 201);
    }

    public function destroy(Customer $customer, CustomerBankDetail $bankDetail)
    {
        abort_if((int) $bankDetail->customer_id !== (int) $customer->id, 404);

        // Soft delete: facilities already created keep pointing at the account they were paid to.
        $bankDetail->delete();

        return response()->noContent();
    }
}
