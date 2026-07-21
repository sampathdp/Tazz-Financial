<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use Illuminate\Support\Facades\Storage;

/**
 * Renders read-only, no-login pages for documents shared via signed link
 * (e.g. "Share to WhatsApp"). Routes are protected by the `signed`
 * middleware — the link is unguessable and expires 60 days after it's
 * generated (see each controller's shareLink()); a tampered or expired
 * URL 403s.
 */
class PublicShareController extends Controller
{
    public function invoice(Invoice $invoice)
    {
        $invoice->load('lines');

        $lines = $invoice->lines->map(fn ($l) => [
            'code' => $l->code ?? '—',
            'name' => $l->name,
            'qty' => $l->qty,
            'rate' => $this->money($l->rate),
            'total' => $this->money($l->qty * $l->rate),
        ]);

        $totals = [
            ['label' => 'Total', 'value' => $this->money($invoice->total)],
            ['label' => 'Paid', 'value' => $this->money($invoice->paid)],
            ['label' => 'Balance Due', 'value' => $this->money($invoice->due), 'grand' => true],
        ];

        return $this->render([
            'docTitle' => 'Sales Invoice',
            'docNo' => $invoice->code ?? $invoice->id,
            'docDate' => optional($invoice->date)->format('d M Y'),
            'partyLabel' => 'Bill To',
            'partyName' => $invoice->customer,
            'partyExtra' => $invoice->rep ? 'Rep: ' . $invoice->rep : null,
            'status' => $invoice->status,
            'lines' => $lines,
            'totals' => $totals,
        ]);
    }

    public function purchaseOrder(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('lines');

        $lines = $purchaseOrder->lines->map(fn ($l) => [
            'code' => $l->code ?? '—',
            'name' => $l->item,
            'qty' => $l->qty,
            'rate' => $this->money($l->cost),
            'total' => $this->money($l->total),
        ]);

        $totals = [
            ['label' => 'Total', 'value' => $this->money($purchaseOrder->total), 'grand' => true],
        ];

        return $this->render([
            'docTitle' => 'Purchase Order',
            'docNo' => $purchaseOrder->code ?? $purchaseOrder->id,
            'docDate' => optional($purchaseOrder->date)->format('d M Y'),
            'partyLabel' => 'Supplier',
            'partyName' => $purchaseOrder->supplier,
            'partyExtra' => $purchaseOrder->supplier_contact,
            'status' => $purchaseOrder->status,
            'lines' => $lines,
            'totals' => $totals,
        ]);
    }

    public function quotation(Quotation $quotation)
    {
        $quotation->load('lines');

        $lines = $quotation->lines->map(fn ($l) => [
            'code' => $l->code ?? '—',
            'name' => $l->name,
            'qty' => $l->qty,
            'rate' => $this->money($l->rate),
            'total' => $this->money($l->qty * $l->rate),
        ]);

        $totals = [
            ['label' => 'Total', 'value' => $this->money($quotation->total), 'grand' => true],
        ];

        return $this->render([
            'docTitle' => 'Quotation',
            'docNo' => $quotation->code ?? $quotation->id,
            'docDate' => optional($quotation->date)->format('d M Y'),
            'partyLabel' => 'Customer',
            'partyName' => $quotation->customer,
            'partyExtra' => $quotation->rep ? 'Rep: ' . $quotation->rep : null,
            'status' => $quotation->status,
            'lines' => $lines,
            'totals' => $totals,
        ]);
    }

    private function render(array $doc)
    {
        $company = CompanyProfile::find(1) ?? new CompanyProfile([
            'name' => 'Distributor Management System',
        ]);
        $hasLogo = (bool) ($company->logo_path ?? false) && Storage::disk('public')->exists($company->logo_path);
        [$statusBg, $statusFg] = $this->statusColors($doc['status']);

        return view('share.document', [
            'docTitle' => $doc['docTitle'],
            'docNo' => $doc['docNo'],
            'docDate' => $doc['docDate'],
            'partyLabel' => $doc['partyLabel'],
            'partyName' => $doc['partyName'] ?: '—',
            'partyExtra' => $doc['partyExtra'],
            'status' => $doc['status'] ?: '—',
            'statusBg' => $statusBg,
            'statusFg' => $statusFg,
            'lines' => $doc['lines'],
            'totals' => $doc['totals'],
            'company' => $company,
            'hasLogo' => $hasLogo,
            'logoUrl' => $hasLogo ? url('/api/company-profile/logo') : null,
        ]);
    }

    private function money($n): string
    {
        $s = number_format((float) ($n ?? 0), 2);
        $s = preg_replace('/\.00$/', '', $s);

        return 'Rs ' . $s;
    }

    private function statusColors(?string $status): array
    {
        $s = strtolower($status ?? '');
        if (preg_match('/(paid|approved|posted|completed|converted|active)/', $s)) {
            return ['#dcfce7', '#166534'];
        }
        if (preg_match('/(pending|partial|draft|open|awaiting)/', $s)) {
            return ['#fef3c7', '#92400e'];
        }
        if (preg_match('/(unpaid|rejected|expired|overdue|inactive)/', $s)) {
            return ['#fee2e2', '#991b1b'];
        }

        return ['#e5e7eb', '#374151'];
    }
}
