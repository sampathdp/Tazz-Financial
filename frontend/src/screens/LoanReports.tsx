/* Loan Management — Reports: five standalone screens (Portfolio Summary,
   PAR/NPL Aging, Arrears, Disbursement, Closed Loans), each its own nav
   entry and route — no tabs. Same "several named exports from one domain
   file" shape as Accounting.tsx. Trend/breakdown charts are computed
   client-side off the list endpoints' own rows where the backend doesn't
   already return a series, so no extra API calls are needed. */
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Card, PageHead, Badge, Field, Input, Table, Td } from '../components/ui'
import { Icon } from '../components/Icon'
import { BarChart, Donut, ProgressRow, AreaChart } from '../components/charts'
import { loanReportsApi, type Loan, type OverdueLoan, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const moneyShort = (v: number) => (Math.abs(v) >= 1000 ? (v / 1000).toFixed(1) + 'k' : v.toFixed(0))
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
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
function Loading() { return <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> }
function ErrBox({ msg }: { msg: string }) { return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{msg}</div> }

/* ---------------------------- Portfolio Summary ---------------------------- */

export function LoanPortfolioSummaryScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    loanReportsApi.get('portfolio-summary').then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [])

  const statusData = data ? [
    { label: 'Active', value: data.activeLoans, color: PALETTE[1] },
    { label: 'Closed', value: data.closedLoans, color: PALETTE[0] },
    { label: 'Pending', value: data.pendingLoans, color: PALETTE[2] },
    { label: 'Written Off', value: data.writtenOffLoans, color: PALETTE[3] },
  ].filter((d) => d.value > 0) : []

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Loan Portfolio Summary" icon="coins" />
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : data && (
        <>
          <TileGrid>
            <Tile icon="coins" label="Total Loans" value={String(data.totalLoans)} />
            <Tile icon="wallet" label="Total Outstanding" value={money(data.totalOutstandingBalance)} />
            <Tile icon="target" label="Avg Loan Size" value={money(data.avgLoanSize)} />
            <Tile icon="arrowUp" label="Loan Growth %" value={pct(data.loanGrowthPct)} tone={data.loanGrowthPct != null ? (data.loanGrowthPct >= 0 ? 'green' : 'red') : 'blue'} />
          </TileGrid>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <ChartCard title="Loans by Status">
              {statusData.length > 0 ? <Donut data={statusData} center={data.totalLoans} /> : <div className="t-3">No loans yet</div>}
            </ChartCard>
            <ChartCard title="Disbursed: Last Month vs This Month">
              <BarChart height={200} format={moneyShort} keys={['value']} colors={[PALETTE[0]]}
                data={[{ label: 'Last Month', value: data.disbursedLastMonthAmount }, { label: 'This Month', value: data.disbursedThisMonthAmount }]} />
            </ChartCard>
          </div>
        </>
      )}
    </div>
  )
}

/* ---------------------------- PAR / NPL Aging ---------------------------- */

export function LoanParAgingScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    loanReportsApi.get('par-aging').then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [])

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Loan PAR / NPL Aging" icon="target"
        actions={data && <Badge tone={data.nplPct > 5 ? 'red' : 'green'} dot>NPL {pct(data.nplPct)}</Badge>} />
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : data && (
        <>
          <TileGrid>
            <Tile icon="wallet" label="Total Active Portfolio" value={money(data.totalPortfolio)} />
            <Tile icon="alert" label="PAR 30 %" value={pct(data.par30Pct)} tone={data.par30Pct > 5 ? 'red' : 'green'} />
            <Tile icon="alert" label="PAR 60 %" value={pct(data.par60Pct)} tone={data.par60Pct > 5 ? 'red' : 'green'} />
            <Tile icon="shield" label="NPL % (90+)" value={pct(data.nplPct)} tone={data.nplPct > 5 ? 'red' : 'green'} />
          </TileGrid>
          <ChartCard title="Portfolio at Risk — Aging Buckets">
            <BarChart height={220} format={moneyShort} keys={['amount']} colors={[PALETTE[3]]}
              data={(data.buckets || []).map((b: any) => ({ label: b.label.replace(' days', 'd').replace(' (NPL)', ''), amount: b.amount }))} />
          </ChartCard>
          <Card pad={0}>
            <Table cols={[{ label: 'Bucket' }, { label: 'Loans', align: 'right' }, { label: 'Outstanding', align: 'right' }, { label: '% of Portfolio', align: 'right' }]}
              rows={data.buckets || []} empty="No data"
              render={(b: any) => <>
                <Td>{b.label}</Td><Td align="right" mono>{b.count}</Td><Td align="right" mono>{money(b.amount)}</Td><Td align="right" mono>{pct(b.pct)}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}

/* ---------------------------- Arrears ---------------------------- */

export function LoanArrearsScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<OverdueLoan[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')

  useEffect(() => {
    setLoading(true); setErr('')
    loanReportsApi.get('arrears', { from: fromDate || undefined, to: toDate || undefined })
      .then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate])

  const totalOverdue = rows.reduce((a, r) => a + Number(r.overdueAmount || 0), 0)
  const totalPenalty = rows.reduce((a, r) => a + Number(r.penaltyDue || 0), 0)

  const byOfficer = useMemo(() => {
    const m = new Map<string, number>()
    for (const r of rows) {
      const name = r.loanDetail?.recoveryOfficer?.name || r.loanDetail?.loanOfficer?.name || 'Unassigned'
      m.set(name, (m.get(name) || 0) + Number(r.overdueAmount || 0) + Number(r.penaltyDue || 0))
    }
    return [...m.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8)
  }, [rows])
  const maxOfficer = Math.max(1, ...byOfficer.map(([, v]) => v))

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Loan Arrears" icon="alert" />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          <TileGrid>
            <Tile icon="alert" label="Overdue Loans" value={String(rows.length)} tone={rows.length > 0 ? 'red' : 'green'} />
            <Tile icon="wallet" label="Overdue Amount" value={money(totalOverdue)} tone="red" />
            <Tile icon="coins" label="Penalty Due" value={money(totalPenalty)} tone="amber" />
          </TileGrid>
          {byOfficer.length > 0 && (
            <ChartCard title="Arrears by Recovery Officer (top 8)">
              {byOfficer.map(([name, v]) => <ProgressRow key={name} label={name} value={v} max={maxOfficer} color="var(--bad)" right={money(v)} />)}
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[
              { label: 'Loan Code' }, { label: 'Customer' }, { label: 'Branch' }, { label: 'Officer' },
              { label: 'Days Overdue', align: 'right' as const }, { label: 'Installments', align: 'right' as const },
              { label: 'Overdue Amount', align: 'right' as const }, { label: 'Penalty Due', align: 'right' as const },
            ]} rows={rows} empty="No overdue loans"
              render={(l: OverdueLoan) => <>
                <Td mono>{l.code}</Td>
                <Td>{l.customer?.fullName || '—'}</Td>
                <Td>{l.branch?.name || '—'}</Td>
                <Td>{l.loanDetail?.recoveryOfficer?.name || l.loanDetail?.loanOfficer?.name || '—'}</Td>
                <Td align="right" mono>{l.daysOverdue}</Td>
                <Td align="right" mono>{l.overdueInstallments}</Td>
                <Td align="right" mono>{money(l.overdueAmount)}</Td>
                <Td align="right" mono>{money(l.penaltyDue)}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}

/* ---------------------------- Disbursement ---------------------------- */

export function LoanDisbursementScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')

  useEffect(() => {
    setLoading(true); setErr('')
    loanReportsApi.get('disbursement', { from: fromDate || undefined, to: toDate || undefined })
      .then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate])

  const total = rows.reduce((a, r) => a + Number(r.principalAmount || 0), 0)

  const trend = useMemo(() => {
    const m = new Map<string, number>()
    for (const r of rows) {
      const d = typeof r.disbursedAt === 'string' ? r.disbursedAt.slice(0, 10) : null
      if (!d) continue
      m.set(d, (m.get(d) || 0) + Number(r.principalAmount || 0))
    }
    return [...m.entries()].sort((a, b) => a[0].localeCompare(b[0]))
  }, [rows])

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Loan Disbursement" icon="upload" />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          <TileGrid>
            <Tile icon="coins" label="Loans Disbursed" value={String(rows.length)} />
            <Tile icon="wallet" label="Total Disbursed" value={money(total)} tone="green" />
          </TileGrid>
          {trend.length > 1 && (
            <ChartCard title="Disbursed Amount by Day">
              <AreaChart height={200} format={moneyShort} labels={trend.map(([d]) => d.slice(5))} series={[{ data: trend.map(([, v]) => v), color: PALETTE[0] }]} />
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[
              { label: 'Loan Code' }, { label: 'Customer' }, { label: 'Branch' }, { label: 'Loan Type' },
              { label: 'Officer' }, { label: 'Principal', align: 'right' as const }, { label: 'Disbursed' }, { label: 'Status', align: 'center' as const },
            ]} rows={rows} empty="No disbursements in this range"
              render={(l: Loan) => <>
                <Td mono>{l.code}</Td>
                <Td>{l.customer?.fullName || '—'}</Td>
                <Td>{l.branch?.name || '—'}</Td>
                <Td>{l.loanDetail?.loanType?.name || '—'}</Td>
                <Td>{l.loanDetail?.loanOfficer?.name || '—'}</Td>
                <Td align="right" mono>{money(l.principalAmount)}</Td>
                <Td>{dateOnly(l.disbursedAt)}</Td>
                <Td align="center"><Badge tone={l.status === 'Active' ? 'green' : 'neutral'} dot>{l.status}</Badge></Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}

/* ---------------------------- Closed Loans ---------------------------- */

export function LoanClosedLoansScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')

  useEffect(() => {
    setLoading(true); setErr('')
    loanReportsApi.get('closed-loans', { from: fromDate || undefined, to: toDate || undefined })
      .then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate])

  const total = rows.reduce((a, r) => a + Number(r.principalAmount || 0), 0)

  const byType = useMemo(() => {
    const m = new Map<string, number>()
    for (const r of rows) m.set(r.loanDetail?.loanType?.name || 'Unknown', (m.get(r.loanDetail?.loanType?.name || 'Unknown') || 0) + 1)
    return [...m.entries()].map(([label, value], i) => ({ label, value, color: PALETTE[i % PALETTE.length] }))
  }, [rows])

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Closed Loans" icon="check" />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          <TileGrid>
            <Tile icon="check" label="Loans Closed" value={String(rows.length)} tone="green" />
            <Tile icon="coins" label="Total Principal" value={money(total)} />
          </TileGrid>
          {byType.length > 0 && (
            <ChartCard title="Closed by Loan Type">
              <Donut data={byType} center={rows.length} />
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[
              { label: 'Loan Code' }, { label: 'Customer' }, { label: 'Branch' }, { label: 'Loan Type' },
              { label: 'Principal', align: 'right' as const }, { label: 'Closed' },
            ]} rows={rows} empty="No closed loans in this range"
              render={(l: Loan) => <>
                <Td mono>{l.code}</Td>
                <Td>{l.customer?.fullName || '—'}</Td>
                <Td>{l.branch?.name || '—'}</Td>
                <Td>{l.loanDetail?.loanType?.name || '—'}</Td>
                <Td align="right" mono>{money(l.principalAmount)}</Td>
                <Td>{dateOnly(l.closedAt)}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}
