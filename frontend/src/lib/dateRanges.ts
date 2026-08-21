/** QuickBooks-style report date range presets — pure date math, no timezone
 *  conversion (uses local Y/M/D directly rather than toISOString(), which
 *  would shift the date near UTC midnight). */
const pad = (n: number) => String(n).padStart(2, '0')
const iso = (y: number, m: number, d: number) => `${y}-${pad(m + 1)}-${pad(d)}`
const toIso = (d: Date) => iso(d.getFullYear(), d.getMonth(), d.getDate())
const lastDayOfMonth = (y: number, m: number) => new Date(y, m + 1, 0).getDate()

export const CUSTOM_RANGE_KEY = 'custom'

export interface DateRangePreset { key: string; label: string; range: () => { from: string; to: string } }

export const DATE_RANGE_PRESETS: DateRangePreset[] = [
  { key: 'today', label: 'Today', range: () => { const d = new Date(); return { from: toIso(d), to: toIso(d) } } },
  { key: 'yesterday', label: 'Yesterday', range: () => { const d = new Date(); d.setDate(d.getDate() - 1); return { from: toIso(d), to: toIso(d) } } },
  { key: 'this-week', label: 'This Week', range: () => { const d = new Date(); const s = new Date(d); s.setDate(d.getDate() - d.getDay()); const e = new Date(s); e.setDate(s.getDate() + 6); return { from: toIso(s), to: toIso(e) } } },
  { key: 'last-week', label: 'Last Week', range: () => { const d = new Date(); const s = new Date(d); s.setDate(d.getDate() - d.getDay() - 7); const e = new Date(s); e.setDate(s.getDate() + 6); return { from: toIso(s), to: toIso(e) } } },
  { key: 'month-to-date', label: 'This Month-to-date', range: () => { const d = new Date(); return { from: iso(d.getFullYear(), d.getMonth(), 1), to: toIso(d) } } },
  { key: 'this-month', label: 'This Month', range: () => { const d = new Date(); return { from: iso(d.getFullYear(), d.getMonth(), 1), to: iso(d.getFullYear(), d.getMonth(), lastDayOfMonth(d.getFullYear(), d.getMonth())) } } },
  { key: 'last-month', label: 'Last Month', range: () => { const d = new Date(); const m = d.getMonth() - 1; const y = d.getFullYear() + (m < 0 ? -1 : 0); const mm = (m + 12) % 12; return { from: iso(y, mm, 1), to: iso(y, mm, lastDayOfMonth(y, mm)) } } },
  { key: 'quarter-to-date', label: 'This Quarter-to-date', range: () => { const d = new Date(); const q = Math.floor(d.getMonth() / 3); return { from: iso(d.getFullYear(), q * 3, 1), to: toIso(d) } } },
  { key: 'this-quarter', label: 'This Quarter', range: () => { const d = new Date(); const q = Math.floor(d.getMonth() / 3); return { from: iso(d.getFullYear(), q * 3, 1), to: iso(d.getFullYear(), q * 3 + 2, lastDayOfMonth(d.getFullYear(), q * 3 + 2)) } } },
  { key: 'last-quarter', label: 'Last Quarter', range: () => { const d = new Date(); let q = Math.floor(d.getMonth() / 3) - 1; let y = d.getFullYear(); if (q < 0) { q = 3; y -= 1 } return { from: iso(y, q * 3, 1), to: iso(y, q * 3 + 2, lastDayOfMonth(y, q * 3 + 2)) } } },
  { key: 'year-to-date', label: 'This Year-to-date', range: () => { const d = new Date(); return { from: iso(d.getFullYear(), 0, 1), to: toIso(d) } } },
  { key: 'this-year', label: 'This Year', range: () => { const y = new Date().getFullYear(); return { from: iso(y, 0, 1), to: iso(y, 11, 31) } } },
  { key: 'last-year', label: 'Last Year', range: () => { const y = new Date().getFullYear() - 1; return { from: iso(y, 0, 1), to: iso(y, 11, 31) } } },
  { key: 'all', label: 'All Dates', range: () => ({ from: '', to: '' }) },
]

/** Which preset (if any) the given from/to currently match — 'custom' if the user has hand-edited the dates away from any preset's range. */
export function detectDateRangePreset(from: string, to: string): string {
  const match = DATE_RANGE_PRESETS.find((p) => { const r = p.range(); return r.from === from && r.to === to })
  return match ? match.key : CUSTOM_RANGE_KEY
}
