<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Shared PDF-export shape for every *ReportController — one Blade view
 * (resources/views/reports/generic.blade.php) rendering an arbitrary list of
 * "sections" (title + columns + rows + optional total row), so a report
 * controller only has to describe its data, not build a view.
 */
class ReportExport
{
    /**
     * @param array<int, array{title?: ?string, columns: array<int, array{key: string, label: string, align?: string, format?: string, width?: string}>, rows: array, totalRow?: ?array, emptyText?: string}> $sections
     */
    public static function pdf(string $reportTitle, array $sections, ?string $subtitle = null, ?string $meta = null)
    {
        return Pdf::loadView('reports.generic', [
            'companyName' => config('app.name'),
            'reportTitle' => $reportTitle,
            'subtitle' => $subtitle,
            'meta' => $meta,
            'sections' => $sections,
            'generatedAt' => now(),
        ])->setPaper('a4');
    }

    public static function filename(string $slug): string
    {
        return $slug . '-' . now()->format('Y-m-d-His') . '.pdf';
    }
}
