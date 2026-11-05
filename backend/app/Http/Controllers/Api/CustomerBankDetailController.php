<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerBankDetail;
use App\Support\Permissions;
use Illuminate\Http\Request;

class CustomerBankDetailController extends Controller
{
    public function index(Request $request, Customer $customer)
    {
        $user = $request->user();
        foreach ([['Customer Management', 'View'], ['Loan Applications', 'View'], ['Loan Disbursement', 'View'], ['Fixed Deposits', 'View']] as [$module, $action]) {
            if (Permissions::userCan($user, $module, $action)) {
                return $customer->bankDetails()->with('bank:id,name')->get();
            }
        }
        abort(403, "You don't have permission to view customer bank accounts.");
    }

    /**
     * Bank accounts are captured from several screens (customer profile, loan
     * application / disbursement, FD placement / payout), so any of those
     * permissions is enough - not only Customer Management.
     */
    private function authorizeManage(Request $request): void
    {
        $user = $request->user();
        $allowed = [
            ['Customer Management', 'Create'], ['Customer Management', 'Edit'],
            ['Loan Applications', 'Create'], ['Loan Applications', 'Edit'],
            ['Loan Disbursement', 'Create'], ['Loan Disbursement', 'Approve'],
            ['Fixed Deposits', 'Create'], ['Fixed Deposits', 'Edit'],
        ];
        foreach ($allowed as [$module, $action]) {
            if (Permissions::userCan($user, $module, $action)) {
                return;
            }
        }
        abort(403, "You don't have permission to manage customer bank accounts.");
    }

    public function update(Request $request, Customer $customer, CustomerBankDetail $bankDetail)
    {
        $this->authorizeManage($request);
        abort_if((int) $bankDetail->customer_id !== (int) $customer->id, 404);

        $data = $request->validate([
            'bank_id' => 'required|exists:banks,id',
            'account_holder' => 'required|string|max:150',
            'bank_branch' => 'nullable|string|max:150',
            // Optional on edit: blank keeps the stored number (the client only ever sees it masked).
            'account_number' => ['nullable', 'string', 'max:34', 'regex:/^[A-Za-z0-9\- ]+$/'],
        ]);
        if (empty($data['account_number'])) {
            unset($data['account_number']);
        }

        $bankDetail->update($data);

        return $bankDetail->load('bank:id,name');
    }

    public function store(Request $request, Customer $customer)
    {
        $this->authorizeManage($request);
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
