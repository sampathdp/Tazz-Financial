/* Expense reports: three standalone screens (Summary, List, By Vendor),
   each its own nav entry and route — no tabs. Same shape as
   LoanReports.tsx. */
import { useEffect, useState, type ReactNode } from 'react'
import { Card, PageHead, Badge, Field, Input, Select, Table, Td , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { ExportButtons } from '../components/ExportButtons'
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import { DateRangeSelect } from '../components/DateRangeSelect'
import { Donut, ProgressRow } from '../components/charts'
import { expenseReportsApi, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportFlatTableToExcel, exportReportToExcel } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const PALETTE = ['var(--ac-bright)', 'var(--ok)', 'var(--warn)', 'var(--bad)', '#8b5cf6', '#14b8a6', '#ec4899', 'var(--tx-3)']

function Tile({ icon, label, value, tone = 'blue' }: { icon: string; label: string; value: string; tone?: 'blue' | 'green' | 'amber' | 'red' }) {
  const tc = { blue: 'var(--ac-bright)', green: 'var(--ok)', amber: 'var(--warn)', red: 'var(--bad)' }[tone]
  const td = { blue: 'var(--ac-dim)', green: 'var(--ok-dim)', amber: 'var(--warn-dim)', red: 'var(--bad-dim)' }[tone]
  return (
    <Card pad={18}>
      <div style={{ width: 38, height: 38, borderRadius: 10, background: td, display: 'grid', placeItems: 'center', color: tc, marginBottom: 14 }}>
        <Icon n={icon} s={20} />
      </div>
      <div className="num" style={{ fontSize: 24, fontWeight: 700, fontFamily: 'Saira', letterSpacing: '-0.01em' }}>{value}</div>
      <div className="t-2" style={{ fontSize: 12, marginTop: 4 }}>{label}</div>
    </Card>
  )
}
function TileGrid({ children }: { children: ReactNode }) {
  return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 16, marginBottom: 16 }}>{children}</div>
}
function ChartCard({ title, children }: { title: string; children: ReactNode }) {
  return <Card style={{ marginBottom: 16 }}><div className="eyebrow" style={{ marginBottom: 14 }}>{title}</div>{children}</Card>
}
function Loading() { return <LoadingBlock /> }
function ErrBox({ msg }: { msg: string }) { return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{msg}</div> }

/* ---------------------------- Summary ---------------------------- */

export function ExpenseSummaryScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true); setErr('')
    expenseReportsApi.get('summary', { from: fromDate || undefined, to: toDate || undefined, branchId })
      .then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, branchId])

  const byCategory = (data?.byCategory || []).map((c: any, i: number) => ({ label: c.name, value: c.amount, color: PALETTE[i % PALETTE.length] })).filter((d: any) => d.value > 0)

  const exportExcel = () => {
    if (!data) return
    void exportReportToExcel({
      sheetName: 'Expense Summary', reportTitle: 'Expense Summary', meta: `Period: ${data.from} to ${data.to}`,
      sections: [
        {
          title: 'Summary', columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right' }],
          rows: [
            { metric: 'Total Amount (Approved/Paid)', value: money(data.totalAmount) }, { metric: 'Paid Amount', value: money(data.paidAmount) },
            { metric: 'Pending Approval Count', value: data.pendingApprovalCount }, { metric: 'Pending Approval Amount', value: money(data.pendingApprovalAmount) },
          ],
        },
        {
          title: 'By Category', columns: [{ label: 'Category', key: 'name' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' }],
          rows: (data.byCategory || []).map((c: any) => ({ name: c.name, amount: Number(c.amount || 0) })), emptyText: 'No expenses in this range',
        },
      ],
    }, `expense-summary-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await expenseReportsApi.pdf('summary', { from: fromDate || undefined, to: toDate || undefined, branchId }), `expense-summary-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Expense Management / Reports" title="Expense Summary" icon="chart"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : data && (
        <>
          <TileGrid>
            <Tile icon="wallet" label="Total Expense" value={money(data.totalAmount)} />
            <Tile icon="coins" label="Paid" value={money(data.paidAmount)} tone="green" />
            <Tile icon="alert" label="Pending Approval" value={String(data.pendingApprovalCount ?? 0)} tone={data.pendingApprovalCount > 0 ? 'red' : 'green'} />
            <Tile icon="alert" label="Pending Amount" value={money(data.pendingApprovalAmount)} tone={data.pendingApprovalAmount > 0 ? 'amber' : 'green'} />
          </TileGrid>
          {byCategory.length > 0 && (
            <ChartCard title="Expense by Category">
              <Donut data={byCategory} center={money(data.totalAmount)} />
            </ChartCard>
          )}
        </>
      )}
    </div>
  )
}

/* ---------------------------- List ---------------------------- */

export function ExpenseListScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [status, setStatus] = useState('')
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true); setErr('')
    expenseReportsApi.get('list', { from: fromDate || undefined, to: toDate || undefined, status: status || undefined, branchId })
      .then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, status, branchId])

  const total = rows.reduce((a, r) => a + Number(r.amount || 0), 0)

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Expense List', meta: `Period: ${fromDate || '—'} to ${toDate || today()}`,
      columns: [
        { label: 'Code', key: 'code' }, { label: 'Date', key: 'date' }, { label: 'Branch', key: 'branch' }, { label: 'Category', key: 'category' }, { label: 'Vendor', key: 'vendor' },
        { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' }, { label: 'Requested By', key: 'requester' },
      ],
      rows: rows.map((e) => ({
        code: e.code, date: dateOnly(e.expenseDate), branch: e.branch?.name || '—', category: e.category?.name || '—', vendor: e.vendor?.name || '—',
        amount: Number(e.amount || 0), status: e.status, requester: e.requester?.name || '—',
      })),
      totalRow: { branch: 'Total', amount: total },
      emptyText: 'No expenses in this range',
    }, `expense-list-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await expenseReportsApi.pdf('list', { from: fromDate || undefined, to: toDate || undefined, status: status || undefined, branchId }), `expense-list-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Expense Management / Reports" title="Expense List" icon="list"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <Field label="Status"><Select value={status} onChange={(e) => setStatus(e.target.value)} style={{ maxWidth: 180 }}>
            <option value="">All</option><option value="Pending">Pending</option><option value="Approved">Approved</option><option value="Rejected">Rejected</option><option value="Paid">Paid</option>
          </Select></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          <TileGrid>
            <Tile icon="doc" label="Expenses" value={String(rows.length)} />
            <Tile icon="wallet" label="Total Amount" value={money(total)} />
          </TileGrid>
          <Card pad={0}>
            <Table cols={[
              { label: 'Code' }, { label: 'Date' }, { label: 'Branch' }, { label: 'Category' }, { label: 'Vendor' },
              { label: 'Amount', align: 'right' as const }, { label: 'Status', align: 'center' as const }, { label: 'Requested By' },
            ]} rows={rows} empty="No expenses in this range"
              render={(e: any) => <>
                <Td mono>{e.code}</Td>
                <Td>{dateOnly(e.expenseDate)}</Td>
                <Td>{e.branch?.name || '—'}</Td>
                <Td>{e.category?.name || '—'}</Td>
                <Td>{e.vendor?.name || '—'}</Td>
                <Td align="right" mono>{money(e.amount)}</Td>
                <Td align="center"><Badge tone={e.status === 'Paid' ? 'green' : e.status === 'Rejected' ? 'red' : e.status === 'Approved' ? 'blue' : 'amber'} dot>{e.status}</Badge></Td>
                <Td>{e.requester?.name || '—'}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}

/* ---------------------------- By Vendor ---------------------------- */

export function ExpenseByVendorScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true); setErr('')
    expenseReportsApi.get('by-vendor', { from: fromDate || undefined, to: toDate || undefined, branchId })
      .then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, branchId])

  const maxAmount = Math.max(1, ...rows.map((r) => Number(r.totalAmount || 0)))

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Expense by Vendor', meta: `Period: ${fromDate || '—'} to ${toDate || today()}`,
      columns: [{ label: 'Vendor', key: 'vendor' }, { label: 'Expenses', key: 'count', align: 'right', format: 'number' }, { label: 'Total Amount', key: 'amount', align: 'right', format: 'money' }],
      rows: rows.map((v) => ({ vendor: v.vendorName, count: v.expenseCount, amount: Number(v.totalAmount || 0) })),
      totalRow: { vendor: 'Total', amount: rows.reduce((a, v) => a + Number(v.totalAmount || 0), 0) },
      emptyText: 'No vendor spend in this range',
    }, `expense-by-vendor-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await expenseReportsApi.pdf('by-vendor', { from: fromDate || undefined, to: toDate || undefined, branchId }), `expense-by-vendor-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Expense Management / Reports" title="Expense by Vendor" icon="store"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          {rows.length > 0 && (
            <ChartCard title="Spend by Vendor">
              {rows.slice(0, 10).map((v) => <ProgressRow key={v.vendorId} label={v.vendorName} value={Number(v.totalAmount || 0)} max={maxAmount} color="var(--ac-bright)" right={money(v.totalAmount)} />)}
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[{ label: 'Vendor' }, { label: 'Expenses', align: 'right' as const }, { label: 'Total Amount', align: 'right' as const }]}
              rows={rows} empty="No vendor spend in this range"
              render={(v: any) => <><Td>{v.vendorName}</Td><Td align="right" mono>{v.expenseCount}</Td><Td align="right" mono>{money(v.totalAmount)}</Td></>} />
          </Card>
        </>
      )}
    </div>
  )
}
