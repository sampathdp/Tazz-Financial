<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FdDeposit;
use App\Models\FdInterestPayment;
use App\Models\FdRenewal;
use App\Models\FixedDeposit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FundingReportController extends Controller
{
    private const TYPES = [
        'active', 'maturing-soon', 'matured', 'monthly-interest', 'interest-paid',
        'deposit-history', 'customer-funding-history', 'renewal-history', 'capital-enhancements',
    ];

    public function show(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        return match ($type) {
            'active' => FixedDeposit::with(['customer:id,full_name,code', 'fundingProduct:id,name,code'])
                ->where('status', 'Active')->orderByDesc('id')->get(),

            'maturing-soon' => FixedDeposit::with(['customer:id,full_name,code', 'fundingProduct:id,name,code'])
                ->where('status', 'Active')
                ->whereDate('maturity_date', '>=', now())
                ->whereDate('maturity_date', '<=', now()->addDays((int) $request->query('days', 30)))
                ->orderBy('maturity_date')->get(),

            'matured' => FixedDeposit::with(['customer:id,full_name,code', 'fundingProduct:id,name,code'])
                ->where('status', 'Active')
                ->whereDate('maturity_date', '<=', now())
                ->orderBy('maturity_date')->get(),

            'monthly-interest' => FdInterestPayment::with(['fixedDeposit:id,fd_number,customer_id', 'fixedDeposit.customer:id,full_name,code'])
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('month'), fn ($q, $m) => $q->whereRaw("DATE_FORMAT(due_date, '%Y-%m') = ?", [$m]))
                ->orderBy('due_date')->get(),

            'interest-paid' => FdInterestPayment::with(['fixedDeposit:id,fd_number,customer_id', 'fixedDeposit.customer:id,full_name,code', 'payer:id,name'])
                ->where('status', 'Paid')
                ->when($request->query('from'), fn ($q, $d) => $q->whereDate('paid_at', '>=', $d))
                ->when($request->query('to'), fn ($q, $d) => $q->whereDate('paid_at', '<=', $d))
                ->orderByDesc('paid_at')->get(),

            'deposit-history' => FdDeposit::with(['customer:id,full_name,code', 'branch:id,name,code', 'fixedDeposit:id,fd_number'])
                ->when($request->query('customerId'), fn ($q, $c) => $q->where('customer_id', $c))
                ->orderByDesc('id')->get(),

            'customer-funding-history' => $this->customerFundingHistory($request),

            'renewal-history' => FdRenewal::with(['oldFixedDeposit:id,fd_number,customer_id', 'oldFixedDeposit.customer:id,full_name,code', 'newFixedDeposit:id,fd_number'])
                ->orderByDesc('id')->get(),

            'capital-enhancements' => FdRenewal::with(['oldFixedDeposit:id,fd_number,customer_id', 'oldFixedDeposit.customer:id,full_name,code', 'newFixedDeposit:id,fd_number', 'additionalDeposit'])
                ->where('additional_deposit_amount', '>', 0)->orderByDesc('id')->get(),

            default => [],
        };
    }

    private function customerFundingHistory(Request $request): array
    {
        $customerId = $request->query('customerId');
        if (!$customerId) {
            throw ValidationException::withMessages(['customerId' => 'customerId is required for this report.']);
        }

        return [
            'deposits' => FdDeposit::where('customer_id', $customerId)->orderByDesc('id')->get(),
            'fixed_deposits' => FixedDeposit::with('fundingProduct:id,name,code')->where('customer_id', $customerId)->orderByDesc('id')->get(),
        ];
    }
}
