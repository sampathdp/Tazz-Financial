<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\CribReport;
use App\Support\ReportExport;

/**
 * Per-customer internal credit report, laid out like a CRIB Consumer Credit
 * Report but computed only from this company's own facilities — see CribReport.
 * Lives under the customer resource (not Reports > Customer Reports, which are
 * all-customer roll-ups).
 */
class CustomerCribReportController extends Controller
{
    public function show(Customer $customer)
    {
        return CribReport::build($customer);
    }

    public function exportPdf(Customer $customer)
    {
        $data = CribReport::build($customer);
        [$title, $sections, $meta] = CribReport::pdfSections($data);

        return ReportExport::pdf($title, $sections, null, $meta)
            ->download(ReportExport::filename('credit-report-'.$customer->code));
    }
}
