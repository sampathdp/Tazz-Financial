<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentNumberLog;

class GeneratedNumberController extends Controller
{
    /**
     * Flattens the normalized number_sequences/document_number_log split back
     * into the single-row shape the Document Tracking screen expects —
     * branch_id/loan_type_id/year live on the shared numberSequence counter
     * row now, not copied onto every log row (see DocumentNumberLog's
     * docblock). sequence isn't stored per-row either; it's the trailing
     * digits of the already-formatted generated_number.
     */
    public function index()
    {
        return DocumentNumberLog::with([
            'numberSequence:id,module,branch_id,loan_type_id,funding_product_id,year',
            'numberSequence.branch:id,name,code',
            'numberSequence.loanType:id,name,code',
            'generator:id,name',
        ])
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (DocumentNumberLog $log) => [
                'id' => $log->id,
                'module' => $log->numberSequence?->module,
                'number' => $log->generated_number,
                'source_type' => $log->source_type,
                'source_id' => $log->source_id,
                'branch_id' => $log->numberSequence?->branch_id,
                'branch' => $log->numberSequence?->branch,
                'loan_type_id' => $log->numberSequence?->loan_type_id,
                'loan_type' => $log->numberSequence?->loanType,
                'year' => $log->numberSequence?->year,
                'sequence' => preg_match('/(\d+)$/', $log->generated_number, $m) ? (int) $m[1] : null,
                'generated_by' => $log->generated_by,
                'generator' => $log->generator,
                'created_at' => $log->generated_at,
            ]);
    }
}
