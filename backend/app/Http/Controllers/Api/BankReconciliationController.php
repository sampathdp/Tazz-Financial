<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $query = BankReconciliation::with(['bankAccount:id,name,bank_id,account_number', 'bankAccount.bank:id,name', 'reconciler:id,name'])
            ->orderByDesc('statement_date');
        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', (int) $request->query('bank_account_id'));
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'statement_date' => [
                'required', 'date',
                Rule::unique('bank_reconciliations')->where(fn ($q) => $q->where('bank_account_id', $request->input('bank_account_id'))),
            ],
            'statement_balance' => 'required|numeric',
            'notes' => 'nullable|string|max:255',
        ]);

        $account = BankAccount::findOrFail($data['bank_account_id']);
        $bookBalance = (string) $account->current_balance;
        $difference = bcsub((string) $data['statement_balance'], $bookBalance, 2);
        $reconciled = abs((float) $difference) < 0.01;

        $recon = BankReconciliation::create($data + [
            'book_balance' => $bookBalance,
            'difference' => $difference,
            'status' => $reconciled ? 'Reconciled' : 'Pending',
            'reconciled_by' => $reconciled ? $request->user()->id : null,
            'reconciled_at' => $reconciled ? now() : null,
        ]);

        return response()->json($recon->load(['bankAccount:id,name,bank_id,account_number', 'bankAccount.bank:id,name', 'reconciler:id,name']), 201);
    }

    public function update(Request $request, BankReconciliation $bankReconciliation)
    {
        $data = $request->validate([
            'statement_date' => 'required|date',
            'statement_balance' => 'required|numeric',
            'notes' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(['Pending', 'Reconciled'])],
        ]);

        $difference = bcsub((string) $data['statement_balance'], (string) $bankReconciliation->book_balance, 2);
        $status = $data['status'] ?? (abs((float) $difference) < 0.01 ? 'Reconciled' : 'Pending');
        $wasReconciled = $bankReconciliation->status === 'Reconciled';
        $nowReconciled = $status === 'Reconciled';

        $bankReconciliation->update($data + [
            'difference' => $difference,
            'status' => $status,
            'reconciled_by' => $nowReconciled ? ($bankReconciliation->reconciled_by ?? $request->user()->id) : null,
            'reconciled_at' => $nowReconciled ? ($wasReconciled ? $bankReconciliation->reconciled_at : now()) : null,
        ]);

        return $bankReconciliation->load(['bankAccount:id,name,bank_id,account_number', 'bankAccount.bank:id,name', 'reconciler:id,name']);
    }

    public function destroy(BankReconciliation $bankReconciliation)
    {
        $bankReconciliation->delete();

        return response()->noContent();
    }
}
