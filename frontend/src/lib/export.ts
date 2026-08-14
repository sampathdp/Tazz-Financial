/* Shared export helpers for report screens — client-side Excel via `xlsx`,
   PDF via a downloaded blob (the actual PDF is rendered server-side by
   dompdf; see AccountingReportController::exportPdf and its Blade views).
   xlsx is ~900KB raw, so it's loaded on demand (see src/excel.ts for the
   same convention on the import side) instead of bundled into every screen. */
import { beginDownload, endDownload } from './downloadProgress'

let _xlsx: typeof import('xlsx') | null = null
const loadXlsx = async (): Promise<typeof import('xlsx')> => (_xlsx ??= await import('xlsx'))

export function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

export type ExcelSheet = { name: string; rows: (string | number | null | undefined)[][] }

export async function exportSheetsToExcel(sheets: ExcelSheet[], filename: string) {
  beginDownload()
  try {
    const XLSX = await loadXlsx()
    const wb = XLSX.utils.book_new()
    for (const s of sheets) {
      const ws = XLSX.utils.aoa_to_sheet(s.rows)
      XLSX.utils.book_append_sheet(wb, ws, s.name.slice(0, 31))
    }
    XLSX.writeFile(wb, filename)
  } finally {
    endDownload()
  }
}
