<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FdDeposit;
use App\Models\FdInterestPayment;
use App\Models\FdRenewal;
use App\Models\FixedDeposit;
use App\Support\CurrentBranch;
use App\Support\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'active' => CurrentBranch::applyScope(FixedDeposit::with(['customer:id,full_name,code', 'fundingProduct:id,name,code'])
                ->where('status', 'Active'))->orderByDesc('id')->get(),

            'maturing-soon' => CurrentBranch::applyScope(FixedDeposit::with(['customer:id,full_name,code', 'fundingProduct:id,name,code'])
                ->where('status', 'Active')
                ->whereDate('maturity_date', '>=', now())
                ->whereDate('maturity_date', '<=', now()->addDays((int) $request->query('days', 30))))
                ->orderBy('maturity_date')->get(),

            'matured' => CurrentBranch::applyScope(FixedDeposit::with(['customer:id,full_name,code', 'fundingProduct:id,name,code'])
                ->where('status', 'Active')
                ->whereDate('maturity_date', '<=', now()))
                ->orderBy('maturity_date')->get(),

            // fd_interest_payments has no branch_id of its own — scope via its fixed deposit's branch.
            'monthly-interest' => FdInterestPayment::with(['fixedDeposit:id,fd_number,customer_id', 'fixedDeposit.customer:id,full_name,code'])
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('month'), fn ($q, $m) => $q->whereRaw("DATE_FORMAT(due_date, '%Y-%m') = ?", [$m]))
                ->whereHas('fixedDeposit', fn ($q) => CurrentBranch::applyScope($q))
                ->orderBy('due_date')->get(),

            'interest-paid' => FdInterestPayment::with(['fixedDeposit:id,fd_number,customer_id', 'fixedDeposit.customer:id,full_name,code', 'payer:id,name'])
                ->where('status', 'Paid')
                ->when($request->query('from'), fn ($q, $d) => $q->whereDate('paid_at', '>=', $d))
                ->when($request->query('to'), fn ($q, $d) => $q->whereDate('paid_at', '<=', $d))
                ->whereHas('fixedDeposit', fn ($q) => CurrentBranch::applyScope($q))
                ->orderByDesc('paid_at')->get(),

            'deposit-history' => CurrentBranch::applyScope(FdDeposit::with(['customer:id,full_name,code', 'branch:id,name,code', 'fixedDeposit:id,fd_number'])
                ->when($request->query('customerId'), fn ($q, $c) => $q->where('customer_id', $c)))
                ->orderByDesc('id')->get(),

            'customer-funding-history' => $this->customerFundingHistory($request),

            // fd_renewals has no branch_id of its own — scope via the old FD's branch.
            'renewal-history' => FdRenewal::with(['oldFixedDeposit:id,fd_number,customer_id', 'oldFixedDeposit.customer:id,full_name,code', 'newFixedDeposit:id,fd_number'])
                ->whereHas('oldFixedDeposit', fn ($q) => CurrentBranch::applyScope($q))
                ->orderByDesc('id')->get(),

            'capital-enhancements' => FdRenewal::with(['oldFixedDeposit:id,fd_number,customer_id', 'oldFixedDeposit.customer:id,full_name,code', 'newFixedDeposit:id,fd_number', 'additionalDeposit'])
                ->where('additional_deposit_amount', '>', 0)
                ->whereHas('oldFixedDeposit', fn ($q) => CurrentBranch::applyScope($q))
                ->orderByDesc('id')->get(),

            default => [],
        };
    }

    private const TITLES = [
        'active' => 'Active Fixed Deposits', 'maturing-soon' => 'Maturing Soon', 'matured' => 'Matured Fixed Deposits',
        'monthly-interest' => 'Monthly Interest', 'interest-paid' => 'Interest Paid', 'deposit-history' => 'Deposit History',
        'customer-funding-history' => 'Customer Funding History', 'renewal-history' => 'Renewal History', 'capital-enhancements' => 'Capital Enhancements',
    ];

    public function exportPdf(Request $request, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(404);
        }

        $data = $this->show($request, $type);
        $sections = match ($type) {
            'active', 'maturing-soon', 'matured' => [$this->fdSection($data)],
            'monthly-interest', 'interest-paid' => [$this->interestSection($data)],
            'deposit-history' => [$this->depositSection($data)],
            'renewal-history', 'capital-enhancements' => [$this->renewalSection($data)],
            'customer-funding-history' => [
                $this->depositSection(collect($data['deposits'])) + ['title' => 'Deposits'],
                $this->fdSection(collect($data['fixed_deposits'])) + ['title' => 'Fixed Deposits'],
            ],
        };

        return ReportExport::pdf(self::TITLES[$type], $sections, null, null)->download(ReportExport::filename($type));
    }

    private function fdSection(Collection $rows): array
    {
        $mapped = $rows->map(fn (FixedDeposit $fd) => [
            'fdNo' => $fd->fd_number, 'customer' => $fd->customer?->full_name ?? '—', 'product' => $fd->fundingProduct?->name ?? '—',
            'principal' => (float) $fd->principal_amount, 'maturity' => $fd->maturity_date?->format('Y-m-d') ?? '—', 'status' => $fd->status,
        ]);

        return [
            'columns' => [
                ['key' => 'fdNo', 'label' => 'FD No'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'product', 'label' => 'Product'],
                ['key' => 'principal', 'label' => 'Principal', 'align' => 'right', 'format' => 'money'], ['key' => 'maturity', 'label' => 'Maturity Date'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
            ],
            'rows' => $mapped->all(), 'totalRow' => ['customer' => 'Total', 'principal' => $mapped->sum('principal')], 'emptyText' => 'No records',
        ];
    }

    private function interestSection(Collection $rows): array
    {
        $mapped = $rows->map(fn (FdInterestPayment $p) => [
            'fdNo' => $p->fixedDeposit?->fd_number ?? '—', 'customer' => $p->fixedDeposit?->customer?->full_name ?? '—',
            'due' => $p->due_date?->format('Y-m-d') ?? '—', 'amount' => (float) $p->amount, 'status' => $p->status, 'paidAt' => $p->paid_at?->format('Y-m-d') ?? '—',
        ]);

        return [
            'columns' => [
                ['key' => 'fdNo', 'label' => 'FD No'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'due', 'label' => 'Due Date'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'], ['key' => 'paidAt', 'label' => 'Paid At'],
            ],
            'rows' => $mapped->all(), 'totalRow' => ['customer' => 'Total', 'amount' => $mapped->sum('amount')], 'emptyText' => 'No records',
        ];
    }

    private function depositSection(Collection $rows): array
    {
        $mapped = $rows->map(fn (FdDeposit $d) => [
            'depositNo' => $d->deposit_number, 'customer' => $d->customer?->full_name ?? '—', 'date' => $d->deposit_date?->format('Y-m-d') ?? '—',
            'method' => $d->payment_method, 'amount' => (float) $d->amount, 'status' => $d->status, 'fdNo' => $d->fixedDeposit?->fd_number ?? '—',
        ]);

        return [
            'columns' => [
                ['key' => 'depositNo', 'label' => 'Deposit No'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'date', 'label' => 'Date'], ['key' => 'method', 'label' => 'Method'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'align' => 'center'], ['key' => 'fdNo', 'label' => 'FD No'],
            ],
            'rows' => $mapped->all(), 'totalRow' => ['customer' => 'Total', 'amount' => $mapped->sum('amount')], 'emptyText' => 'No records',
        ];
    }

    private function renewalSection(Collection $rows): array
    {
        $mapped = $rows->map(fn (FdRenewal $r) => [
            'oldFd' => $r->oldFixedDeposit?->fd_number ?? '—', 'newFd' => $r->newFixedDeposit?->fd_number ?? '—', 'customer' => $r->oldFixedDeposit?->customer?->full_name ?? '—',
            'oldPrincipal' => (float) $r->old_principal, 'additional' => (float) $r->additional_deposit_amount, 'newPrincipal' => (float) $r->new_principal, 'renewedAt' => $r->renewed_at?->format('Y-m-d') ?? '—',
        ]);

        return [
            'columns' => [
                ['key' => 'oldFd', 'label' => 'Old FD'], ['key' => 'newFd', 'label' => 'New FD'], ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'oldPrincipal', 'label' => 'Old Principal', 'align' => 'right', 'format' => 'money'], ['key' => 'additional', 'label' => 'Additional', 'align' => 'right', 'format' => 'money'],
                ['key' => 'newPrincipal', 'label' => 'New Principal', 'align' => 'right', 'format' => 'money'], ['key' => 'renewedAt', 'label' => 'Renewed At'],
            ],
            'rows' => $mapped->all(), 'totalRow' => ['customer' => 'Total', 'oldPrincipal' => $mapped->sum('oldPrincipal'), 'additional' => $mapped->sum('additional'), 'newPrincipal' => $mapped->sum('newPrincipal')], 'emptyText' => 'No records',
        ];
    }

    private function customerFundingHistory(Request $request): array
    {
        $customerId = $request->query('customerId');
        if (!$customerId) {
            throw ValidationException::withMessages(['customerId' => 'customerId is required for this report.']);
        }

        return [
            'deposits' => CurrentBranch::applyScope(FdDeposit::where('customer_id', $customerId))->orderByDesc('id')->get(),
            'fixed_deposits' => CurrentBranch::applyScope(FixedDeposit::with('fundingProduct:id,name,code')->where('customer_id', $customerId))->orderByDesc('id')->get(),
        ];
    }
}
