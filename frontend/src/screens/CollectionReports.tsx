/* Loan Management — Collection Reports: four standalone screens (Daily Cash,
   Collection Summary, Officer Performance, Trend), each its own nav entry
   and route — no tabs. Same shape as LoanReports.tsx / Accounting.tsx. */
import { useEffect, useState, type ReactNode } from 'react'
import { Card, PageHead, Field, Input, Select, Table, Td , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { ExportButtons } from '../components/ExportButtons'
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import { DateRangeSelect } from '../components/DateRangeSelect'
import { Donut, Gauge, ProgressRow, AreaChart } from '../components/charts'
import { collectionReportsApi, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportFlatTableToExcel } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const moneyShort = (v: number) => (Math.abs(v) >= 1000 ? (v / 1000).toFixed(1) + 'k' : v.toFixed(0))
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v.toFixed(2)}%`)
const PALETTE = ['var(--ac-bright)', 'var(--ok)', 'var(--warn)', 'var(--bad)', '#8b5cf6', '#14b8a6']

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

/* ---------------------------- Daily Cash ---------------------------- */

export function CollectionDailyCashScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true); setErr('')
    collectionReportsApi.get('daily-cash', { date, branchId }).then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [date, branchId])

  const methods = data ? Object.entries(data.byMethod || {}).map(([label, value], i) => ({ label, value: Number(value), color: PALETTE[i % PALETTE.length] })) : []

  const exportExcel = () => {
    if (!data) return
    void exportFlatTableToExcel({
      reportTitle: 'Daily Cash Collection', meta: `Date: ${data.date}  •  ${data.count ?? 0} payment(s)`,
      columns: [
        { label: 'Receipt', key: 'receipt' }, { label: 'Loan', key: 'loan' }, { label: 'Customer', key: 'customer' }, { label: 'Branch', key: 'branch' },
        { label: 'Method', key: 'method' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'Received By', key: 'receiver' }, { label: 'Time', key: 'time' },
      ],
      rows: (data.payments || []).map((p: any) => ({
        receipt: p.receiptNo || p.code, loan: p.facility?.code || '—', customer: p.facility?.customer?.fullName || '—', branch: p.branch?.name || '—',
        method: p.paymentMethod, amount: Number(p.amount || 0), receiver: p.receiver?.name || '—', time: typeof p.paidAt === 'string' ? p.paidAt.slice(11, 16) : '—',
      })),
      totalRow: { customer: 'Total', amount: Number(data.totalAmount || 0) },
      emptyText: 'No collections recorded',
    }, `daily-cash-${data.date}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await collectionReportsApi.pdf('daily-cash', { date, branchId }), `daily-cash-${date}.pdf`)

  return (
    <div>
      <PageHead crumbs="Loan Management / Collection Reports" title="Daily Cash Collection" icon="wallet"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="Date"><Input type="date" value={date} onChange={(e) => setDate(e.target.value)} style={{ maxWidth: 200 }} /></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : data && (
        <>
          <TileGrid>
            <Tile icon="wallet" label="Total Collected" value={money(data.totalAmount)} tone="green" />
            <Tile icon="receipt" label="Payments" value={String(data.count ?? 0)} />
          </TileGrid>
          {methods.length > 0 && (
            <ChartCard title="Collected by Payment Method">
              <Donut data={methods} center={money(data.totalAmount)} />
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[
              { label: 'Receipt' }, { label: 'Loan' }, { label: 'Customer' }, { label: 'Branch' },
              { label: 'Method' }, { label: 'Amount', align: 'right' as const }, { label: 'Received By' }, { label: 'Time' },
            ]} rows={data.payments || []} empty="No collections recorded"
              render={(p: any) => <>
                <Td mono>{p.receiptNo || p.code}</Td>
                <Td mono>{p.facility?.code || '—'}</Td>
                <Td>{p.facility?.customer?.fullName || '—'}</Td>
                <Td>{p.branch?.name || '—'}</Td>
                <Td>{p.paymentMethod}</Td>
                <Td align="right" mono>{money(p.amount)}</Td>
                <Td>{p.receiver?.name || '—'}</Td>
                <Td>{typeof p.paidAt === 'string' ? p.paidAt.slice(11, 16) : '—'}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}

/* ---------------------------- Collection Summary ---------------------------- */

export function CollectionSummaryScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true); setErr('')
    collectionReportsApi.get('collection-summary', { from: fromDate || undefined, to: toDate || undefined, branchId })
      .then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, branchId])

  const exportExcel = () => {
    if (!data) return
    void exportFlatTableToExcel({
      reportTitle: 'Collection Summary', meta: `Period: ${data.from} to ${data.to}`,
      columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right' }],
      rows: [
        { metric: 'Due Amount', value: money(data.dueAmount) }, { metric: 'Collected Amount', value: money(data.collectedAmount) },
        { metric: 'Outstanding Amount', value: money(data.outstandingAmount) }, { metric: 'Collection Rate %', value: pct(data.collectionRatePct) },
      ],
    }, `collection-summary-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await collectionReportsApi.pdf('collection-summary', { from: fromDate || undefined, to: toDate || undefined, branchId }), `collection-summary-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Loan Management / Collection Reports" title="Collection Summary" icon="chart"
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
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 300px', gap: 16 }}>
          <TileGrid>
            <Tile icon="wallet" label="Due Amount" value={money(data.dueAmount)} />
            <Tile icon="coins" label="Collected Amount" value={money(data.collectedAmount)} tone="green" />
            <Tile icon="alert" label="Outstanding" value={money(data.outstandingAmount)} tone={data.outstandingAmount > 0 ? 'red' : 'green'} />
          </TileGrid>
          <ChartCard title="Collection Rate">
            <div className="row center">
              <Gauge pct={Math.round(data.collectionRatePct ?? 0)} label="collected of due" color={data.collectionRatePct >= 90 ? 'var(--ok)' : data.collectionRatePct < 70 ? 'var(--bad)' : 'var(--warn)'} />
            </div>
          </ChartCard>
        </div>
      )}
    </div>
  )
}

/* ---------------------------- Officer Performance ---------------------------- */

export function CollectionOfficerPerformanceScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true); setErr('')
    collectionReportsApi.get('officer-performance', { from: fromDate || undefined, to: toDate || undefined, branchId })
      .then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, branchId])

  const maxDue = Math.max(1, ...rows.map((r) => Number(r.dueAmount || 0)))

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Officer Performance', meta: `Period: ${fromDate || '—'} to ${toDate || today()}`,
      columns: [
        { label: 'Officer', key: 'officer' }, { label: 'Loans', key: 'loans', align: 'right', format: 'number' }, { label: 'Due', key: 'due', align: 'right', format: 'money' },
        { label: 'Collected', key: 'collected', align: 'right', format: 'money' }, { label: 'Collection Rate %', key: 'rate', align: 'right', format: 'percent' },
      ],
      rows: rows.map((r) => ({ officer: r.officerName, loans: r.loanCount, due: Number(r.dueAmount || 0), collected: Number(r.collectedAmount || 0), rate: r.collectionRatePct })),
      totalRow: { officer: 'Total', due: rows.reduce((a, r) => a + Number(r.dueAmount || 0), 0), collected: rows.reduce((a, r) => a + Number(r.collectedAmount || 0), 0) },
      emptyText: 'No data for this period',
    }, `officer-performance-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await collectionReportsApi.pdf('officer-performance', { from: fromDate || undefined, to: toDate || undefined, branchId }), `officer-performance-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Loan Management / Collection Reports" title="Officer Performance" icon="users"
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
            <ChartCard title="Due vs Collected by Officer">
              {rows.map((r) => (
                <div key={r.officerId} style={{ marginBottom: 4 }}>
                  <ProgressRow label={r.officerName} value={Number(r.collectedAmount || 0)} max={maxDue} color={(r.collectionRatePct ?? 0) >= 90 ? 'var(--ok)' : 'var(--warn)'} right={`${money(r.collectedAmount)} / ${money(r.dueAmount)}`} />
                </div>
              ))}
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[
              { label: 'Officer' }, { label: 'Loans', align: 'right' as const }, { label: 'Due', align: 'right' as const },
              { label: 'Collected', align: 'right' as const }, { label: 'Collection Rate %', align: 'right' as const },
            ]} rows={rows} empty="No data for this period"
              render={(r: any) => <>
                <Td>{r.officerName}</Td>
                <Td align="right" mono>{r.loanCount}</Td>
                <Td align="right" mono>{money(r.dueAmount)}</Td>
                <Td align="right" mono>{money(r.collectedAmount)}</Td>
                <Td align="right" mono>{pct(r.collectionRatePct)}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}

/* ---------------------------- Trend ---------------------------- */

export function CollectionTrendScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [bucket, setBucket] = useState<'daily' | 'weekly' | 'monthly'>('daily')
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true); setErr('')
    collectionReportsApi.get('trend', { bucket, branchId }).then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [bucket, branchId])

  const rows = data?.rows || []
  const total = rows.reduce((a: number, r: any) => a + Number(r.collectedAmount || 0), 0)

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: `Collection Trend (${bucket[0].toUpperCase()}${bucket.slice(1)})`, meta: data?.since ? `Since: ${data.since}` : undefined,
      columns: [
        { label: bucket === 'daily' ? 'Date' : bucket === 'weekly' ? 'Week' : 'Month', key: 'period' },
        { label: 'Collected', key: 'collected', align: 'right', format: 'money' }, { label: 'Payments', key: 'payments', align: 'right', format: 'number' },
      ],
      rows: rows.map((r: any) => ({ period: r.bucketKey, collected: Number(r.collectedAmount || 0), payments: r.paymentCount })),
      totalRow: { period: 'Total', collected: total, payments: rows.reduce((a: number, r: any) => a + Number(r.paymentCount || 0), 0) },
      emptyText: 'No data',
    }, `collection-trend-${bucket}-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await collectionReportsApi.pdf('trend', { bucket, branchId }), `collection-trend-${bucket}-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Loan Management / Collection Reports" title="Collection Trend" icon="arrowUp"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="Bucket"><Select value={bucket} onChange={(e) => setBucket(e.target.value as any)} style={{ maxWidth: 200 }}>
            <option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
          </Select></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          <TileGrid>
            <Tile icon="wallet" label="Total Collected" value={money(total)} tone="green" />
            <Tile icon="receipt" label="Buckets" value={String(rows.length)} />
          </TileGrid>
          {rows.length > 1 && (
            <ChartCard title="Collected Amount Trend">
              <AreaChart height={220} format={moneyShort} labels={rows.map((r: any) => String(r.bucketKey).slice(bucket === 'monthly' ? 0 : 5))} series={[{ data: rows.map((r: any) => Number(r.collectedAmount || 0)), color: PALETTE[0] }]} />
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[{ label: bucket === 'daily' ? 'Date' : bucket === 'weekly' ? 'Week' : 'Month' }, { label: 'Collected', align: 'right' as const }, { label: 'Payments', align: 'right' as const }]}
              rows={rows} empty="No data"
              render={(r: any) => <><Td mono>{r.bucketKey}</Td><Td align="right" mono>{money(r.collectedAmount)}</Td><Td align="right" mono>{r.paymentCount}</Td></>} />
          </Card>
        </>
      )}
    </div>
  )
}
