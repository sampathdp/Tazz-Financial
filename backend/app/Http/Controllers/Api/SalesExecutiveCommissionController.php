<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesExecutiveCommission;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesExecutiveCommissionController extends Controller
{
    private const PAYMENT_METHODS = ['Cash', 'Bank', 'Cheque'];

    private const RELATIONS = [
        'loan:id,code,customer_id,principal_amount,status',
        'loan.customer:id,full_name',
        'loan.schedules:id,loan_id,status,due_date',
        'loanType:id,name',
        'salesExecutive:id,name',
        'paidBy:id,name',
    ];

    public function index(Request $request)
    {
        $query = SalesExecutiveCommission::with(self::RELATIONS);
        if ($request->filled('sales_executive_id')) {
            $query->where('sales_executive_id', $request->input('sales_executive_id'));
        }
        if ($request->filled('loan_type_id')) {
            $query->where('loan_type_id', $request->input('loan_type_id'));
        }

        $rows = $query->get()->map(fn ($c) => self::present($c));

        if ($request->filled('status')) {
            $rows = $rows->where('commission_status', $request->input('status'))->values();
        }

        return $rows;
    }

    public function pay(Request $request, SalesExecutiveCommission $commission)
    {
        if ($commission->paid_at) {
            return response()->json(['message' => 'This commission has already been paid.'], 422);
        }
        $commission->loadMissing('loan.schedules');
        if (self::paidInstallments($commission) < $commission->required_installments) {
            return response()->json(['message' => 'This commission is not yet eligible for payment.'], 422);
        }

        $data = $request->validate([
            'payment_date' => 'required|date',
            'payment_amount' => 'required|numeric|min:0',
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'reference_no' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:255',
        ]);

        $commission->update([
            'paid_at' => $data['payment_date'],
            'payment_amount' => $data['payment_amount'],
            'payment_method' => $data['payment_method'],
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'paid_by' => $request->user()->id,
        ]);

        return self::present($commission->load(self::RELATIONS));
    }

    private static function paidInstallments(SalesExecutiveCommission $commission): int
    {
        return $commission->loan->schedules->where('status', 'Paid')->count();
    }

    private static function present(SalesExecutiveCommission $c): array
    {
        $loan = $c->loan;
        $paidInstallments = self::paidInstallments($c);
        $today = Carbon::today();

        $commissionStatus = $c->paid_at
            ? 'Paid'
            : ($paidInstallments >= $c->required_installments ? 'Eligible' : 'Pending');

        $paymentStatus = $loan->status === 'Closed'
            ? 'Closed'
            : ($loan->schedules->contains(fn ($s) => $s->due_date->lessThan($today) && $s->status !== 'Paid') ? 'Overdue' : 'Current');

        return [
            'id' => $c->id,
            'loan_id' => $loan->id,
            'loan_code' => $loan->code,
            'customer_name' => $loan->customer?->full_name,
            'loan_type_id' => $c->loan_type_id,
            'loan_type_name' => $c->loanType?->name,
            'sales_executive_id' => $c->sales_executive_id,
            'sales_executive_name' => $c->salesExecutive?->name,
            'loan_amount' => $loan->principal_amount,
            'commission_amount' => $c->commission_amount,
            'required_installments' => $c->required_installments,
            'paid_installments' => $paidInstallments,
            'commission_status' => $commissionStatus,
            'payment_status' => $paymentStatus,
            'paid_at' => $c->paid_at,
            'payment_amount' => $c->payment_amount,
            'payment_method' => $c->payment_method,
            'reference_no' => $c->reference_no,
            'notes' => $c->notes,
        ];
    }
}
