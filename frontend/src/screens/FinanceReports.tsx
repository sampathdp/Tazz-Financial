/* Finance & cash reports: one tab-switched screen over every report the
   backend FinanceReportController exposes (daily cash, cash position, cash
   flow, transfers, floats, pending / approved-unpaid requests, reversals,
   variance, audit). Same "one generic screen, several bindings" idea as
   FundingReports.tsx — each report is a small declarative spec (tiles +
   table sections + row mappers) and the screen renders / exports it. */
import { useEffect, useState, type ReactNode } from 'react'
import { Card, PageHead, Field, Input, Select, Table, Td, LoadingBlock } from '../components/ui'
import { ExportButtons } from '../components/ExportButtons'
import { DateRangeSelect } from '../components/DateRangeSelect'
import { financeReportsApi, type FinanceReportType, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportReportToExcel, type ExcelColumn } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)
const money = (v: unknown) => Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const ymd = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const dt = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 16).replace('T', ' ') : '—')

type Col = ExcelColumn
interface RepSection {
  title?: string
  columns: Col[]
  rows: (d: any) => Record<string, unknown>[]
  total?: (d: any) => Record<string, unknown>
  empty?: string
}
interface Report {
  id: FinanceReportType
  label: string
  ranged?: boolean
  meta?: (d: any) => string | undefined
  tiles?: (d: any) => { label: string; value: string; tone?: Tone }[]
  sections: RepSection[]
}
type Tone = 'blue' | 'green' | 'amber' | 'red'

/* --------------------------------- specs --------------------------------- */

const REPORTS: Report[] = [
  {
    id: 'daily-cash', label: 'Daily Cash', ranged: true,
    meta: (d) => `Period: ${d.from} to ${d.to}`,
    tiles: (d) => [
      { label: 'Opening', value: money(d.totals.opening) },
      { label: 'Inflow', value: money(d.totals.inflow), tone: 'green' },
      { label: 'Outflow', value: money(d.totals.outflow), tone: 'amber' },
      { label: 'Closing', value: money(d.totals.closing), tone: 'blue' },
    ],
    sections: [{
      columns: [
        { label: 'Cash Account', key: 'account' }, { label: 'Type', key: 'accountType' }, { label: 'Branch', key: 'branch' },
        { label: 'Opening', key: 'opening', align: 'right', format: 'money' }, { label: 'Inflow', key: 'inflow', align: 'right', format: 'money' },
        { label: 'Outflow', key: 'outflow', align: 'right', format: 'money' }, { label: 'Closing', key: 'closing', align: 'right', format: 'money' },
        { label: 'Txns', key: 'txnCount', align: 'right' },
      ],
      rows: (d) => d.rows,
      total: (d) => ({ account: 'Total', opening: d.totals.opening, inflow: d.totals.inflow, outflow: d.totals.outflow, closing: d.totals.closing, txnCount: d.totals.txnCount }),
      empty: 'No cash accounts',
    }],
  },
  {
    id: 'cash-position', label: 'Cash Position',
    tiles: (d) => [
      { label: 'Total Cash (Active)', value: money(d.totalCache), tone: 'green' },
      { label: 'Total Drift vs Ledger', value: money(d.totalDrift), tone: Math.abs(Number(d.totalDrift)) > 0.005 ? 'red' : 'blue' },
    ],
    sections: [{
      columns: [
        { label: 'Cash Account', key: 'account' }, { label: 'Code', key: 'code' }, { label: 'Type', key: 'accountType' }, { label: 'Branch', key: 'branch' }, { label: 'Custodian', key: 'custodian' },
        { label: 'Cache', key: 'cacheBalance', align: 'right', format: 'money' }, { label: 'Ledger', key: 'glBalance', align: 'right', format: 'money' },
        { label: 'Drift', key: 'drift', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' },
      ],
      rows: (d) => d.rows.map((r: any) => ({ ...r, glBalance: r.glBalance ?? '—', drift: r.drift ?? '—' })),
      total: (d) => ({ account: 'Total (Active)', cacheBalance: d.totalCache, drift: d.totalDrift }),
      empty: 'No cash accounts',
    }],
  },
  {
    id: 'cash-flow', label: 'Cash Flow', ranged: true,
    meta: (d) => `Period: ${d.from} to ${d.to}`,
    tiles: (d) => [
      { label: 'Cash In', value: money(d.totalIn), tone: 'green' },
      { label: 'Cash Out', value: money(d.totalOut), tone: 'amber' },
      { label: 'Net Cash', value: money(d.netCash), tone: Number(d.netCash) < 0 ? 'red' : 'blue' },
      { label: 'Transactions', value: String(d.txnCount) },
    ],
    sections: [{
      columns: [
        { label: 'Transaction Type', key: 'type' }, { label: 'Count', key: 'count', align: 'right' },
        { label: 'Cash In', key: 'cashIn', align: 'right', format: 'money' }, { label: 'Cash Out', key: 'cashOut', align: 'right', format: 'money' },
        { label: 'Net Cash', key: 'netCash', align: 'right', format: 'money' },
      ],
      rows: (d) => Object.entries(d.byType || {}).map(([type, v]: [string, any]) => ({ type, ...v })),
      total: (d) => ({ type: 'Total', cashIn: d.totalIn, cashOut: d.totalOut, netCash: d.netCash }),
      empty: 'No transactions in this range',
    }],
  },
  {
    id: 'transfers', label: 'Transfers & Floats', ranged: true,
    meta: (d) => `Period: ${d.from} to ${d.to}`,
    tiles: (d) => [
      { label: 'Total Moved', value: money(d.total), tone: 'blue' },
      { label: 'Movements', value: String(d.count) },
    ],
    sections: [{
      columns: [
        { label: 'Code', key: 'code' }, { label: 'Date', key: 'date' }, { label: 'Type', key: 'txnType' }, { label: 'From', key: 'from' }, { label: 'To', key: 'to' },
        { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' }, { label: 'By', key: 'by' },
      ],
      rows: (d) => d.rows.map((r: any) => ({ ...r, date: ymd(r.date) })),
      total: (d) => ({ code: 'Total', amount: d.total }),
      empty: 'No transfers in this range',
    }],
  },
  {
    id: 'floats', label: 'Cash Floats', ranged: true,
    meta: (d) => `Period: ${d.from} to ${d.to}`,
    tiles: (d) => [
      { label: 'Open Float Balance', value: money(d.openTotal), tone: 'blue' },
      { label: 'Settlement Shortage', value: money(d.settlementShortage), tone: 'red' },
      { label: 'Settlement Excess', value: money(d.settlementExcess), tone: 'green' },
    ],
    sections: [
      {
        title: 'Open Floats',
        columns: [
          { label: 'Float', key: 'float' }, { label: 'Branch', key: 'branch' }, { label: 'Custodian', key: 'custodian' }, { label: 'Home Till', key: 'home' },
          { label: 'Balance', key: 'balance', align: 'right', format: 'money' },
        ],
        rows: (d) => d.openFloats,
        total: (d) => ({ float: 'Total', balance: d.openTotal }),
        empty: 'No open floats',
      },
      {
        title: 'Settlements',
        columns: [
          { label: 'Float', key: 'float' }, { label: 'Custodian', key: 'custodian' }, { label: 'Issued', key: 'issued', align: 'right', format: 'money' },
          { label: 'Counted', key: 'counted', align: 'right', format: 'money' }, { label: 'Shortage', key: 'shortage', align: 'right', format: 'money' },
          { label: 'Excess', key: 'excess', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' },
        ],
        rows: (d) => d.settlements,
        total: (d) => ({ float: 'Total', shortage: d.settlementShortage, excess: d.settlementExcess }),
        empty: 'No settlements in this range',
      },
    ],
  },
  {
    id: 'requests-pending', label: 'Pending Requests',
    tiles: (d) => [
      { label: 'Pending Value', value: money(d.total), tone: 'amber' },
      { label: 'Requests', value: String(d.count) },
    ],
    sections: [{
      columns: [
        { label: 'Code', key: 'code' }, { label: 'Type', key: 'requestType' }, { label: 'Payee', key: 'payee' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' },
        { label: 'Status', key: 'status', align: 'center' }, { label: 'Requested By', key: 'requestedBy' }, { label: 'Age (d)', key: 'ageDays', align: 'right' },
      ],
      rows: (d) => d.rows,
      total: (d) => ({ code: 'Total', amount: d.total }),
      empty: 'Nothing pending',
    }],
  },
  {
    id: 'requests-unpaid', label: 'Approved — Unpaid',
    tiles: (d) => [
      { label: 'Approved, Unpaid Value', value: money(d.total), tone: 'amber' },
      { label: 'Requests', value: String(d.count) },
    ],
    sections: [{
      columns: [
        { label: 'Code', key: 'code' }, { label: 'Type', key: 'requestType' }, { label: 'Payee', key: 'payee' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' },
        { label: 'Status', key: 'status', align: 'center' }, { label: 'Approved By', key: 'approvedBy' }, { label: 'Age (d)', key: 'ageDays', align: 'right' },
      ],
      rows: (d) => d.rows,
      total: (d) => ({ code: 'Total', amount: d.total }),
      empty: 'Nothing approved and unpaid',
    }],
  },
  {
    id: 'reversals', label: 'Reversals', ranged: true,
    meta: (d) => `Period: ${d.from} to ${d.to}`,
    tiles: (d) => [
      { label: 'Reversed Value', value: money(d.total), tone: 'red' },
      { label: 'Reversals', value: String(d.count) },
    ],
    sections: [{
      columns: [
        { label: 'Original', key: 'code' }, { label: 'Type', key: 'txnType' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' },
        { label: 'Reversal', key: 'reversalCode' }, { label: 'Reason', key: 'reason' }, { label: 'By', key: 'by' },
      ],
      rows: (d) => d.rows,
      total: (d) => ({ code: 'Total', amount: d.total }),
      empty: 'No reversals in this range',
    }],
  },
  {
    id: 'variance', label: 'Cash Variance', ranged: true,
    meta: (d) => `Period: ${d.from} to ${d.to}`,
    tiles: (d) => [
      { label: 'Total Shortage', value: money(d.totalShortage), tone: 'red' },
      { label: 'Total Excess', value: money(d.totalExcess), tone: 'green' },
      { label: 'Net', value: money(d.net), tone: Number(d.net) < 0 ? 'red' : 'blue' },
    ],
    sections: [{
      columns: [
        { label: 'Source', key: 'source' }, { label: 'Reference', key: 'ref' }, { label: 'Branch', key: 'branch' }, { label: 'Date', key: 'date' },
        { label: 'Expected', key: 'expected', align: 'right', format: 'money' }, { label: 'Counted', key: 'counted', align: 'right', format: 'money' },
        { label: 'Shortage', key: 'shortage', align: 'right', format: 'money' }, { label: 'Excess', key: 'excess', align: 'right', format: 'money' },
      ],
      rows: (d) => d.rows.map((r: any) => ({ ...r, date: ymd(r.date) })),
      total: (d) => ({ source: 'Total', shortage: d.totalShortage, excess: d.totalExcess }),
      empty: 'No variances in this range',
    }],
  },
  {
    id: 'audit', label: 'Transaction Audit', ranged: true,
    meta: (d) => `Period: ${d.from} to ${d.to}`,
    tiles: (d) => [{ label: 'Events', value: String(d.count) }],
    sections: [
      {
        title: 'By Action',
        columns: [{ label: 'Action', key: 'action' }, { label: 'Count', key: 'count', align: 'right' }],
        rows: (d) => Object.entries(d.byAction || {}).map(([action, count]) => ({ action, count })),
        empty: 'No activity in this range',
      },
      {
        title: 'By Actor',
        columns: [{ label: 'Actor', key: 'actor' }, { label: 'Count', key: 'count', align: 'right' }],
        rows: (d) => Object.entries(d.byActor || {}).map(([actor, count]) => ({ actor, count })),
        empty: 'No activity in this range',
      },
      {
        title: 'Recent Events',
        columns: [
          { label: 'When', key: 'when' }, { label: 'Actor', key: 'actor' }, { label: 'Action', key: 'action' }, { label: 'Subject', key: 'subject' },
          { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'Status', key: 'status' }, { label: 'Reason', key: 'reason' },
        ],
        rows: (d) => (d.recent || []).map((r: any) => ({ ...r, when: dt(r.when), amount: r.amount ?? '—' })),
        empty: 'No activity in this range',
      },
    ],
  },
]

/* --------------------------------- screen -------------------------------- */

function Tile({ label, value, tone = 'blue' }: { label: string; value: string; tone?: Tone }) {
  const tc = { blue: 'var(--ac-bright)', green: 'var(--ok)', amber: 'var(--warn)', red: 'var(--bad)' }[tone]
  return (
    <Card pad={18}>
      <div className="num" style={{ fontSize: 22, fontWeight: 700, fontFamily: 'Saira', letterSpacing: '-0.01em', color: tc }}>{value}</div>
      <div className="t-2" style={{ fontSize: 12, marginTop: 4 }}>{label}</div>
    </Card>
  )
}

function cell(row: Record<string, unknown>, col: Col): ReactNode {
  const v = row[col.key]
  if (col.format === 'money' && typeof v !== 'string') return money(v)
  return v === undefined || v === null || v === '' ? '—' : String(v)
}

export function FinanceReportsScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [type, setType] = useState<FinanceReportType>('daily-cash')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  const spec = REPORTS.find((r) => r.id === type)!

  useEffect(() => {
    setLoading(true); setErr('')
    const params = spec.ranged ? { from: fromDate || undefined, to: toDate || undefined } : {}
    financeReportsApi.get(type, params)
      .then(setData)
      .catch((ex) => setErr(getErrorText(ex, 'Failed to load report')))
      .finally(() => setLoading(false))
  }, [type, fromDate, toDate])

  const exportExcel = () => {
    if (!data) return
    void exportReportToExcel({
      sheetName: spec.label, reportTitle: spec.label, meta: spec.meta?.(data),
      sections: spec.sections.map((s) => ({
        title: s.title, columns: s.columns, rows: s.rows(data), totalRow: s.total?.(data), emptyText: s.empty,
      })),
    }, `finance-${type}-${today()}.xlsx`)
  }
  const exportPdf = async () => {
    const params = spec.ranged ? { from: fromDate || undefined, to: toDate || undefined } : {}
    downloadBlob(await financeReportsApi.pdf(type, params), `finance-${type}-${today()}.pdf`)
  }

  return (
    <div>
      <PageHead crumbs="Finance / Reports" title="Finance Reports" icon="receipt"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="Report">
            <Select value={type} onChange={(e) => setType(e.target.value as FinanceReportType)}>
              {REPORTS.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}
            </Select>
          </Field>
          {spec.ranged && <>
            <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
            <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
            <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          </>}
        </div>
      </Card>
      {loading ? <LoadingBlock /> : err ? (
        <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{err}</div>
      ) : data && (
        <>
          {spec.tiles && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 16, marginBottom: 16 }}>
              {spec.tiles(data).map((t, i) => <Tile key={i} {...t} />)}
            </div>
          )}
          {spec.sections.map((s, si) => {
            const rows = s.rows(data)
            const total = s.total?.(data)
            return (
              <Card key={si} pad={0} style={{ marginBottom: 16 }}>
                {s.title && <div className="eyebrow" style={{ padding: '14px 20px 0' }}>{s.title}</div>}
                <Table
                  cols={s.columns.map((c) => ({ label: c.label, align: c.align === 'center' ? 'center' : c.align === 'right' ? 'right' : undefined }))}
                  rows={total ? [...rows, { ...total, __total: true }] : rows}
                  empty={s.empty || 'No data'}
                  render={(row: Record<string, unknown>) => <>
                    {s.columns.map((c) => (
                      <Td key={c.key} align={c.align === 'center' ? 'center' : c.align === 'right' ? 'right' : undefined}
                        mono={c.format === 'money'} c={row.__total ? 'var(--tx-1)' : undefined}>
                        {cell(row, c)}
                      </Td>
                    ))}
                  </>} />
              </Card>
            )
          })}
        </>
      )}
    </div>
  )
}
