/* Shared export helpers for report screens — every screen calls
   exportReportToExcel/exportFlatTableToExcel for Excel and downloadBlob for
   the PDF blob the server hands back (see AccountingReportController and its
   siblings' exportPdf methods + resources/views/reports/*.blade.php). One
   place to change if the "look" of an export needs to change app-wide.

   Uses xlsx-js-style (a styling-capable fork of SheetJS) instead of the base
   `xlsx` package — the community `xlsx` write path silently drops cell
   styles (bold headers, fills, borders, number formats), which is the whole
   point of a "clean, better design" export. `xlsx` itself stays in use for
   the unrelated import/parsing side (see src/excel.ts). ~1MB raw, so it's
   loaded on demand like the parsing side. */
import { beginDownload, endDownload } from './downloadProgress'

let _xlsx: typeof import('xlsx-js-style') | null = null
const loadXlsx = async (): Promise<typeof import('xlsx-js-style')> => (_xlsx ??= await import('xlsx-js-style'))

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

/* ---------- palette (fixed — exports are static documents, independent of the live in-app theme/accent) ---------- */
const NAVY = '1F3A5F'
const NAVY_TEXT = 'FFFFFF'
const SECTION_FILL = 'DCE6F1'
const TOTAL_FILL = 'F2F4F7'
const ZEBRA_FILL = 'F8FAFC'
const BORDER = 'D5DCE5'
const GRAY_TEXT = '6B7280'

/* xlsx-js-style types `CellObject.s` as `any` — its shape (font/fill/border/alignment) is
   documented but not typed, so we define our own minimal shape for the helpers below. */
interface CellStyle {
  font?: { bold?: boolean; italic?: boolean; sz?: number; color?: { rgb: string } }
  fill?: { fgColor: { rgb: string } }
  alignment?: { horizontal?: 'left' | 'right' | 'center'; vertical?: 'center' }
  border?: Record<'top' | 'bottom' | 'left' | 'right', { style: string; color: { rgb: string } }>
}
const thinBorder = { style: 'thin', color: { rgb: BORDER } } as const
const bordered = { top: thinBorder, bottom: thinBorder, left: thinBorder, right: thinBorder }

const titleStyle: CellStyle = { font: { bold: true, sz: 14, color: { rgb: NAVY } } }
const subtitleStyle: CellStyle = { font: { italic: true, sz: 10.5, color: { rgb: GRAY_TEXT } } }
const metaStyle: CellStyle = { font: { sz: 9.5, color: { rgb: GRAY_TEXT } } }
const sectionStyle: CellStyle = { font: { bold: true, sz: 11, color: { rgb: NAVY } }, fill: { fgColor: { rgb: SECTION_FILL } } }
const headerStyle = (align: 'left' | 'right' | 'center'): CellStyle => ({
  font: { bold: true, sz: 10, color: { rgb: NAVY_TEXT } },
  fill: { fgColor: { rgb: NAVY } },
  alignment: { horizontal: align, vertical: 'center' },
  border: bordered,
})
const cellStyle = (align: 'left' | 'right' | 'center', zebra: boolean): CellStyle => ({
  font: { sz: 10 },
  fill: zebra ? { fgColor: { rgb: ZEBRA_FILL } } : undefined,
  alignment: { horizontal: align, vertical: 'center' },
  border: bordered,
})
const totalStyle = (align: 'left' | 'right' | 'center'): CellStyle => ({
  font: { bold: true, sz: 10.5 },
  fill: { fgColor: { rgb: TOTAL_FILL } },
  alignment: { horizontal: align, vertical: 'center' },
  border: { ...bordered, top: { style: 'medium', color: { rgb: NAVY } } },
})

export type ExcelFormat = 'text' | 'number' | 'money' | 'percent' | 'date'
const NUMBER_FORMATS: Record<ExcelFormat, string | undefined> = {
  text: undefined,
  number: '#,##0',
  money: '#,##0.00;[Red]-#,##0.00',
  percent: '0.00"%"',
  date: undefined,
}

export interface ExcelColumn {
  label: string
  key: string
  align?: 'left' | 'right' | 'center'
  format?: ExcelFormat
  width?: number
}
export interface ExcelSection {
  title?: string
  columns: ExcelColumn[]
  rows: Record<string, unknown>[]
  totalRow?: Record<string, unknown>
  emptyText?: string
}
export interface ExcelReportSheet {
  sheetName: string
  reportTitle: string
  subtitle?: string
  meta?: string
  sections: ExcelSection[]
}

/** Builds one styled worksheet (title/subtitle/meta banner, then each section as a bordered, zebra-striped table with a totals row) and triggers the download. */
export async function exportReportToExcel(sheet: ExcelReportSheet, filename: string) {
  beginDownload()
  try {
    const XLSX = await loadXlsx()
    const ws: import('xlsx-js-style').WorkSheet = {}
    const widths: number[] = []
    let r = 0
    const put = (c: number, v: unknown, s: CellStyle, z?: string) => {
      const addr = XLSX.utils.encode_cell({ r, c })
      const isNum = typeof v === 'number'
      ws[addr] = { v: (v ?? '') as string | number, t: isNum ? 'n' : 's', s, ...(z && isNum ? { z } : {}) }
      widths[c] = Math.max(widths[c] ?? 8, String(v ?? '').length + 2)
    }
    const mergedTitleRow = (text: string, s: CellStyle) => { put(0, text, s); r += 1 }

    mergedTitleRow(sheet.reportTitle, titleStyle)
    if (sheet.subtitle) mergedTitleRow(sheet.subtitle, subtitleStyle)
    if (sheet.meta) mergedTitleRow(sheet.meta, metaStyle)
    r += 1

    const merges: import('xlsx-js-style').Range[] = []
    for (const section of sheet.sections) {
      const cols = section.columns
      if (section.title) {
        put(0, section.title, sectionStyle)
        merges.push({ s: { r, c: 0 }, e: { r, c: cols.length - 1 } })
        r += 1
      }
      cols.forEach((col, c) => put(c, col.label, headerStyle(col.align ?? (c === 0 ? 'left' : 'right'))))
      r += 1

      if (section.rows.length === 0) {
        put(0, section.emptyText ?? 'No data for this range', { font: { italic: true, sz: 10, color: { rgb: GRAY_TEXT } }, border: bordered })
        merges.push({ s: { r, c: 0 }, e: { r, c: cols.length - 1 } })
        r += 1
      } else {
        section.rows.forEach((row, i) => {
          const zebra = i % 2 === 1
          cols.forEach((col, c) => put(c, row[col.key] as string | number, cellStyle(col.align ?? (c === 0 ? 'left' : 'right'), zebra), NUMBER_FORMATS[col.format ?? 'text']))
          r += 1
        })
      }

      if (section.totalRow) {
        cols.forEach((col, c) => put(c, section.totalRow![col.key] as string | number, totalStyle(col.align ?? (c === 0 ? 'left' : 'right')), NUMBER_FORMATS[col.format ?? 'text']))
        r += 1
      }
      r += 1
    }

    ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: Math.max(r - 1, 0), c: Math.max(widths.length - 1, 0) } })
    ws['!cols'] = widths.map((w) => ({ wch: Math.min(Math.max(w, 10), 42) }))
    ws['!merges'] = merges

    const wb = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(wb, ws, sheet.sheetName.slice(0, 31))
    XLSX.writeFile(wb, filename)
  } finally {
    endDownload()
  }
}

/** Convenience wrapper for the common case: one flat table (a list report — Disbursements, Arrears, Closed Loans, etc.), no sub-sections. */
export async function exportFlatTableToExcel(
  opts: { reportTitle: string; subtitle?: string; meta?: string; columns: ExcelColumn[]; rows: Record<string, unknown>[]; totalRow?: Record<string, unknown>; emptyText?: string; sheetName?: string },
  filename: string,
) {
  await exportReportToExcel(
    { sheetName: opts.sheetName ?? opts.reportTitle, reportTitle: opts.reportTitle, subtitle: opts.subtitle, meta: opts.meta, sections: [{ columns: opts.columns, rows: opts.rows, totalRow: opts.totalRow, emptyText: opts.emptyText }] },
    filename,
  )
}
