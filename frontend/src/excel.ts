/* Excel parsing helpers. Uses SheetJS (xlsx) in the browser.
   Three flavours used by Procurement:
     - parsePoExcel       : item list (PART NO / DESCRIPTION / HS CODE / QTY / FOB USD).
     - parseCostingExcel  : same item lines but with the per-line allocation columns
                            from the costing template (returns ParsedCostingLine).
                            Also picks up any "extra" label+amount rows below for
                            backward compat. */
// Types only — erased at build time, so importing them costs nothing.
import type * as XLSXType from 'xlsx'

// The xlsx library is ~900KB raw / ~330KB gzipped. Load it on demand (first time
// a user actually imports/parses a spreadsheet) instead of on initial page load.
let _xlsx: typeof import('xlsx') | null = null
async function loadXlsx(): Promise<typeof import('xlsx')> {
  return (_xlsx ??= await import('xlsx'))
}

export interface ParsedLine {
  code: string
  hsCode?: string
  item: string
  qty: number
  cost: number
  total: number
}


const lower = (s: any) => String(s ?? '').trim().toLowerCase()

function rowsFromSheet(sheet: XLSXType.WorkSheet): any[][] {
  // _xlsx is always populated by readWorkbook() before this runs.
  return _xlsx!.utils.sheet_to_json(sheet, { header: 1, blankrows: false, defval: '' }) as any[][]
}

interface HeaderIdx {
  code?: number
  hsCode?: number
  item?: number
  qty?: number
  cost?: number
  total?: number
  sellingWo?: number
  sellingWith?: number
  avgCost?: number
  fifoCost?: number
  price?: number

  // Customer columns
  custName?: number
  custCompany?: number
  custContact?: number
  custAddress?: number
  custEmail?: number
  custCredit?: number
  custLimit?: number
  custRep?: number
  // Supplier columns
  supCode?: number
  supName?: number
  supContact?: number
  supEmail?: number
  supCity?: number
  supCountry?: number
  supTax?: number
  supTerms?: number
  supCurrency?: number
}

function detectHeader(rows: any[][]) {
  for (let i = 0; i < Math.min(rows.length, 25); i++) {
    const r = rows[i].map(lower)
    const itemCol = r.findIndex((c) => /item|description|particular|name/.test(c))
    const qtyCol = r.findIndex((c) => /qty|quantity/.test(c))
    // Require the two keywords to live in distinct cells — otherwise a single
    // long note row containing both words can be misread as the header.
    if (itemCol >= 0 && qtyCol >= 0 && itemCol !== qtyCol) {
      const idx: HeaderIdx = {}
      r.forEach((c, j) => {
        // `cc` is the header text with all whitespace removed, for exact/robust
        // matching of the costing-template columns (which are split across two
        // lines in the template, e.g. "UNIT PRICE\nFOB-USD").
        const cc = c.replace(/\s+/g, '')
        if (idx.code == null && /(^|\b)(code|part\s*no|part\s*number|part)\b/.test(c)) idx.code = j
        if (idx.hsCode == null && /hs\s*code|h\.s\.?\s*code/.test(c)) idx.hsCode = j
        if (idx.item == null && /description|particular|^item$|name/.test(c)) idx.item = j
        if (idx.qty == null && /qty|quantity/.test(c)) idx.qty = j
        if (idx.cost == null && /unit\s*price|fob.*usd|price|rate|unit\s*cost|^cost/.test(c)) idx.cost = j
        if (idx.total == null && /total\s*amount|^total\b|amount/.test(c)) idx.total = j
        if (idx.sellingWo == null && /selling.*w\/?o.*vat/.test(c)) idx.sellingWo = j
        if (idx.sellingWith == null && /selling.*with.*vat/.test(c)) idx.sellingWith = j
        // Customer columns
        if (idx.custName == null && /(^|\b)(customer|name|customer\s*name)\b/.test(c)) idx.custName = j
        if (idx.custCompany == null && /(^|\b)(company|company\s*name)\b/.test(c)) idx.custCompany = j
        if (idx.custContact == null && /contact|phone/.test(c)) idx.custContact = j
        if (idx.custAddress == null && /address/.test(c)) idx.custAddress = j
        if (idx.custEmail == null && /email/.test(c)) idx.custEmail = j
        if (idx.custCredit == null && /credit/.test(c)) idx.custCredit = j
        if (idx.custLimit == null && /limit/.test(c)) idx.custLimit = j
        if (idx.custRep == null && /rep|sales\s*rep/.test(c)) idx.custRep = j
        // Supplier columns
        if (idx.supCode == null && /(^|\b)(code|supplier\s*code)\b/.test(c)) idx.supCode = j
        if (idx.supName == null && /(^|\b)(supplier|name|supplier\s*name)\b/.test(c)) idx.supName = j
        if (idx.supContact == null && /contact|phone/.test(c)) idx.supContact = j
        if (idx.supEmail == null && /email/.test(c)) idx.supEmail = j
        if (idx.supCity == null && /city/.test(c)) idx.supCity = j
        if (idx.supCountry == null && /country/.test(c)) idx.supCountry = j
        if (idx.supTax == null && /tax|vat/.test(c)) idx.supTax = j
        if (idx.supTerms == null && /payment|terms/.test(c)) idx.supTerms = j
        if (idx.supCurrency == null && /currency|ccy/.test(c)) idx.supCurrency = j
      })
      // PART NO appears twice in the supplier template (numeric # + actual code).
      // Prefer the right-most non-numeric Part column as the code by re-scanning.
      const partCols: number[] = []
      r.forEach((c, j) => { if (/part\s*no/.test(c)) partCols.push(j) })
      if (partCols.length > 1) idx.code = partCols[partCols.length - 1]
      return { headerRow: i, idx }
    }
    // Also try to detect customer/supplier/item simple headers without qty
    const isCustomer = r.some((c) => /customer|contact/i.test(c)) && r.some((c) => /credit|limit|address/i.test(c))
    const isSupplier = r.some((c) => /supplier|email/i.test(c)) && r.some((c) => /country|tax|terms/i.test(c))
    const isItemMaster = !isCustomer && !isSupplier && r.some((c) => /code/i.test(c)) && r.some((c) => /name|item|description/i.test(c))
    if (isCustomer || isSupplier || isItemMaster) {
      const idx: HeaderIdx = {}
      r.forEach((c, j) => {
        const cc = c.replace(/\s+/g, '')
        // Items
        if (idx.code == null && /(^|\b)(code|part\s*no|part\s*number|part|item\s*code)\b/.test(c)) idx.code = j
        if (idx.hsCode == null && /hs\s*code|h\.s\.?\s*code/.test(c)) idx.hsCode = j
        if (idx.item == null && /description|particular|^item$|name|item\s*name/.test(c)) idx.item = j
        if (idx.avgCost == null && /avg\s*cost/i.test(c)) idx.avgCost = j
        if (idx.fifoCost == null && /fifo\s*cost/i.test(c)) idx.fifoCost = j
        if (idx.price == null && /selling\s*price|retail\s*price/i.test(c)) idx.price = j
        // Customer
        if (idx.custName == null && /(^|\b)(customer|name|customer\s*name)\b/.test(c)) idx.custName = j
        if (idx.custCompany == null && /(^|\b)(company|company\s*name)\b/.test(c)) idx.custCompany = j
        if (idx.custContact == null && /contact|phone/.test(c)) idx.custContact = j
        if (idx.custAddress == null && /address/.test(c)) idx.custAddress = j
        if (idx.custEmail == null && /email/.test(c)) idx.custEmail = j
        if (idx.custCredit == null && /credit/.test(c)) idx.custCredit = j
        if (idx.custLimit == null && /limit/.test(c)) idx.custLimit = j
        if (idx.custRep == null && /rep|sales\s*rep/.test(c)) idx.custRep = j
        // Supplier
        if (idx.supCode == null && /(^|\b)(code|supplier\s*code)\b/.test(c)) idx.supCode = j
        if (idx.supName == null && /(^|\b)(supplier|name|supplier\s*name)\b/.test(c)) idx.supName = j
        if (idx.supContact == null && /contact|phone/.test(c)) idx.supContact = j
        if (idx.supEmail == null && /email/.test(c)) idx.supEmail = j
        if (idx.supCity == null && /city/.test(c)) idx.supCity = j
        if (idx.supCountry == null && /country/.test(c)) idx.supCountry = j
        if (idx.supTax == null && /tax|vat/.test(c)) idx.supTax = j
        if (idx.supTerms == null && /payment|terms/.test(c)) idx.supTerms = j
        if (idx.supCurrency == null && /currency|ccy/.test(c)) idx.supCurrency = j
      })
      if (idx.custName != null || idx.supName != null || (idx.code != null && idx.item != null)) {
        return { headerRow: i, idx }
      }
    }
  }
  return null
}

function num(v: any): number {
  if (v == null || v === '') return 0
  const s = String(v).replace(/[$,\s]/g, '')
  const n = Number(s)
  return Number.isFinite(n) ? n : 0
}

function parseRows(rows: any[][]) {
  const h = detectHeader(rows)
  if (!h) return { lines: [] as ParsedLine[], end: 0 }
  const { headerRow, idx } = h
  const lines: ParsedLine[] = []
  let end = rows.length
  let skippedEmpty = 0
  for (let i = headerRow + 1; i < rows.length; i++) {
    const r = rows[i]
    const isBlank = !r || r.every((c) => String(c ?? '').trim() === '')
    // "GRAND TOTAL" footer may live in any column (merged cells collapse to col A).
    if (r && r.some((c) => /grand\s*total/i.test(String(c ?? '')))) { end = i; break }
    const itemCell = idx.item != null ? r?.[idx.item] : ''
    const qtyCell = idx.qty != null ? r?.[idx.qty] : ''
    const item = String(itemCell ?? '').trim()
    const qty = num(qtyCell)
    // Tolerate up to 3 leading non-data rows after the detected header
    // (multi-line headers like "UNIT PRICE" / "FOB-USD" split across two rows).
    if ((isBlank || !item || qty <= 0) && lines.length === 0 && skippedEmpty < 3) {
      skippedEmpty++
      continue
    }
    if (isBlank) { end = i; break }
    if (!item) { end = i; break }
    const cost = idx.cost != null ? num(r[idx.cost]) : 0
    const total = idx.total != null ? num(r[idx.total]) : qty * cost
    const code = idx.code != null ? String(r[idx.code] ?? '').trim() : ''
    const col = (i?: number) => (i != null ? num(r[i]) : undefined)
    lines.push({
      code,
      // HS codes are codes, not numbers — preserve as text (Excel may have stripped leading zeros).
      hsCode: idx.hsCode != null ? String(r[idx.hsCode] ?? '').trim() : undefined,
      item,
      qty,
      cost,
      total: total || qty * cost,
    })
  }
  return { lines, end }
}

export async function readWorkbook(file: File): Promise<XLSXType.WorkBook> {
  const XLSX = await loadXlsx()
  const buf = await file.arrayBuffer()
  return XLSX.read(buf, { type: 'array' })
}

export async function fileToBase64(file: File): Promise<string> {
  const buf = await file.arrayBuffer()
  let bin = ''
  const bytes = new Uint8Array(buf)
  for (let i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i])
  return btoa(bin)
}

/** Pick the sheet whose name best matches `prefer` (e.g. "PO"), else first sheet. */
function pickSheet(wb: XLSXType.WorkBook, prefer: RegExp): XLSXType.WorkSheet {
  const match = wb.SheetNames.find((n) => prefer.test(n))
  return wb.Sheets[match || wb.SheetNames[0]]
}

export interface ParsedItem {
  code: string
  hsCode?: string
  name: string
  avgCost?: number
  fifoCost?: number
  price?: number
}

/** Parse an Item Master sheet — picks up code + description + hs code from any
 *  sheet that has those columns (handles the "PO" tab in the supplier template). */
export async function parseItemMasterExcel(file: File): Promise<ParsedItem[]> {
  const wb = await readWorkbook(file)
  const sheet = pickSheet(wb, /^po\b|item|master/i)
  const rows = rowsFromSheet(sheet)
  const h = detectHeader(rows)
  if (!h) return []
  const { headerRow, idx } = h
  if (idx.code == null || idx.item == null) return []
  const items: ParsedItem[] = []
  for (let i = headerRow + 1; i < rows.length; i++) {
    const r = rows[i]
    if (!r) continue
    if (r.some((c) => /grand\s*total/i.test(String(c ?? '')))) break
    const code = String(r[idx.code] ?? '').trim()
    const name = String(r[idx.item] ?? '').trim()
    if (!code || !name) continue
    const hsCode = idx.hsCode != null ? String(r[idx.hsCode] ?? '').trim() : ''
    
    const avgCost = idx.avgCost != null ? num(r[idx.avgCost]) : undefined
    const fifoCost = idx.fifoCost != null ? num(r[idx.fifoCost]) : undefined
    const price = idx.price != null ? num(r[idx.price]) : undefined

    items.push({ code, name, hsCode: hsCode || undefined, avgCost, fifoCost, price })
  }
  return items
}

export async function parsePoExcel(file: File): Promise<ParsedLine[]> {
  const wb = await readWorkbook(file)
  const sheet = pickSheet(wb, /^po\b|purchase/i)
  const rows = rowsFromSheet(sheet)
  return parseRows(rows).lines
}


export interface ParsedCustomer {
  name: string
  company_name?: string
  contact?: string
  address?: string
  email?: string
  credit?: number
  limit?: number
  rep?: string
}

export async function parseCustomerExcel(file: File): Promise<ParsedCustomer[]> {
  const wb = await readWorkbook(file)
  const sheet = pickSheet(wb, /customer|client/i)
  const rows = rowsFromSheet(sheet)
  const h = detectHeader(rows)
  if (!h) return []
  const { headerRow, idx } = h
  if (idx.custName == null) return []
  const customers: ParsedCustomer[] = []
  for (let i = headerRow + 1; i < rows.length; i++) {
    const r = rows[i]
    if (!r) continue
    if (r.some((c) => /grand\s*total/i.test(String(c ?? '')))) break
    const name = String(r[idx.custName] ?? '').trim()
    if (!name) continue
    customers.push({
      name,
      company_name: idx.custCompany != null ? String(r[idx.custCompany] ?? '').trim() : undefined,
      contact: idx.custContact != null ? String(r[idx.custContact] ?? '').trim() : undefined,
      address: idx.custAddress != null ? String(r[idx.custAddress] ?? '').trim() : undefined,
      email: idx.custEmail != null ? String(r[idx.custEmail] ?? '').trim() : undefined,
      credit: idx.custCredit != null ? num(r[idx.custCredit]) : undefined,
      limit: idx.custLimit != null ? num(r[idx.custLimit]) : undefined,
      rep: idx.custRep != null ? String(r[idx.custRep] ?? '').trim() : undefined,
    })
  }
  return customers
}

export interface ParsedSupplier {
  code?: string
  name: string
  contact?: string
  email?: string
  city?: string
  country?: string
  taxNo?: string
  paymentTerms?: string
  currency?: string
}

export async function parseSupplierExcel(file: File): Promise<ParsedSupplier[]> {
  const wb = await readWorkbook(file)
  const sheet = pickSheet(wb, /supplier|vendor/i)
  const rows = rowsFromSheet(sheet)
  const h = detectHeader(rows)
  if (!h) return []
  const { headerRow, idx } = h
  if (idx.supName == null) return []
  const suppliers: ParsedSupplier[] = []
  for (let i = headerRow + 1; i < rows.length; i++) {
    const r = rows[i]
    if (!r) continue
    if (r.some((c) => /grand\s*total/i.test(String(c ?? '')))) break
    const name = String(r[idx.supName] ?? '').trim()
    if (!name) continue
    suppliers.push({
      code: idx.supCode != null ? String(r[idx.supCode] ?? '').trim() : undefined,
      name,
      contact: idx.supContact != null ? String(r[idx.supContact] ?? '').trim() : undefined,
      email: idx.supEmail != null ? String(r[idx.supEmail] ?? '').trim() : undefined,
      city: idx.supCity != null ? String(r[idx.supCity] ?? '').trim() : undefined,
      country: idx.supCountry != null ? String(r[idx.supCountry] ?? '').trim() : undefined,
      taxNo: idx.supTax != null ? String(r[idx.supTax] ?? '').trim() : undefined,
      paymentTerms: idx.supTerms != null ? String(r[idx.supTerms] ?? '').trim() : undefined,
      currency: idx.supCurrency != null ? String(r[idx.supCurrency] ?? '').trim() : undefined,
    })
  }
  return suppliers
}
