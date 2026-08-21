<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentNumberLog;
use App\Models\NumberSequence;
use Illuminate\Http\Request;

class GeneratedNumberController extends Controller
{
    private static function mapRow(DocumentNumberLog $log): array
    {
        return [
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
        ];
    }

    /**
     * Flattens the normalized number_sequences/document_number_log split back
     * into the single-row shape the Document Tracking screen expects —
     * branch_id/loan_type_id/year live on the shared numberSequence counter
     * row now, not copied onto every log row (see DocumentNumberLog's
     * docblock). sequence isn't stored per-row either; it's the trailing
     * digits of the already-formatted generated_number.
     *
     * With no `page` param, returns the old capped-at-500 shape (kept only
     * for compatibility with any other caller); `page` opts into real
     * server-side module/year/search filtering + pagination, which the
     * Document Tracking screen now always sends — the old cap silently hid
     * anything past the newest 500 numbers ever generated (company-wide,
     * across every module), including from the module/year filter dropdowns
     * themselves, so filtering for an older year could show "no matches"
     * even when matches existed.
     */
    public function index(Request $request)
    {
        $query = DocumentNumberLog::with([
            'numberSequence:id,module,branch_id,loan_type_id,funding_product_id,year',
            'numberSequence.branch:id,name,code',
            'numberSequence.loanType:id,name,code',
            'generator:id,name',
        ]);

        if (!$request->filled('page')) {
            return $query->orderByDesc('id')->limit(500)->get()->map(fn (DocumentNumberLog $log) => self::mapRow($log));
        }

        if ($request->filled('search')) {
            $query->where('generated_number', 'like', '%' . $request->search . '%');
        }
        if ($request->filled('module') || $request->filled('year')) {
            $query->whereHas('numberSequence', function ($q) use ($request) {
                if ($request->filled('module')) {
                    $q->where('module', $request->module);
                }
                if ($request->filled('year')) {
                    $q->where('year', $request->year);
                }
            });
        }

        $paginated = $query->orderByDesc('id')->paginate((int) $request->input('perPage', 50));
        $paginated->getCollection()->transform(fn (DocumentNumberLog $log) => self::mapRow($log));

        return $paginated;
    }

    /** Distinct years any number has ever been generated in — powers the Document Tracking year filter, which can't be derived from a single (possibly filtered/paginated) page of rows. */
    public function years()
    {
        return NumberSequence::whereNotNull('year')->distinct()->orderByDesc('year')->pluck('year');
    }
}
