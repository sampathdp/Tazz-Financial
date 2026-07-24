<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanPostDatedCheque;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanPostDatedChequeController extends Controller
{
    private const STATUSES = ['Pending', 'Handed', 'Realized', 'Bounced', 'Returned'];

    public function index(Loan $loan)
    {
        return $loan->postDatedCheques()->with('creator:id,name')->get();
    }

    public function store(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'cheque_no' => 'required|string|max:32',
            'bank_name' => 'required|string|max:128',
            'cheque_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
        ]);

        $cheque = $loan->postDatedCheques()->create([
            'branch_id' => $loan->branch_id,
            'cheque_no' => $data['cheque_no'],
            'bank_name' => $data['bank_name'],
            'cheque_date' => $data['cheque_date'],
            'amount' => $data['amount'],
            'status' => 'Pending',
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($cheque->load('creator:id,name'), 201);
    }

    public function updateStatus(Request $request, LoanPostDatedCheque $cheque)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'notes' => 'nullable|string|max:255',
        ]);

        $cheque->update([
            'status' => $data['status'],
            'handed_at' => $data['status'] === 'Handed' ? now() : $cheque->handed_at,
            'notes' => $data['notes'] ?? $cheque->notes,
        ]);

        return $cheque->load('creator:id,name');
    }

    public function destroy(LoanPostDatedCheque $cheque)
    {
        $cheque->delete();

        return response()->noContent();
    }
}
