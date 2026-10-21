/* Loan Management — Reports: five standalone screens (Portfolio Summary,
   PAR/NPL Aging, Arrears, Disbursement, Closed Loans), each its own nav
   entry and route — no tabs. Same "several named exports from one domain
   file" shape as Accounting.tsx. Trend/breakdown charts are computed
   client-side off the list endpoints' own rows where the backend doesn't
   already return a series, so no extra API calls are needed. */
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Card, PageHead, Badge, Field, Input, Select, Btn, Table, Td, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { ExportButtons } from '../components/ExportButtons'
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import { DateRangeSelect } from '../components/DateRangeSelect'
import { BarChart, Donut, ProgressRow, AreaChart } from '../components/charts'
import { loanReportsApi, loanTypesApi, api, type Loan, type OverdueLoan, type AuthUser, type LoanType, type LoanDetailReportRow, type LoanStatus } from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportFlatTableToExcel, exportReportToExcel, type ExcelColumn } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)

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
function Loading() { return <LoadingBlock /> }
function ErrBox({ msg }: { msg: string }) { return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{msg}</div> }

/* ---------------------------- Portfolio Summary ---------------------------- */

export function LoanPortfolioSummaryScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [loanTypeId, setLoanTypeId] = useState<number | ''>('')

  useEffect(() => { loanTypesApi.list().then((t) => setLoanTypes(t.filter((x) => x.isProduct))).catch(() => setLoanTypes([])) }, [])

  useEffect(() => {
    setLoading(true)
    loanReportsApi.get('portfolio-summary', { branchId, loanTypeId: loanTypeId || undefined }).then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [branchId, loanTypeId])

  const statusData = data ? [
    { label: 'Active', value: data.activeLoans, color: PALETTE[1] },
    { label: 'Closed', value: data.closedLoans, color: PALETTE[0] },
    { label: 'Pending', value: data.pendingLoans, color: PALETTE[2] },
    { label: 'Written Off', value: data.writtenOffLoans, color: PALETTE[3] },
  ].filter((d) => d.value > 0) : []

  const exportExcel = () => {
    if (!data) return
    void exportFlatTableToExcel({
      reportTitle: 'Loan Portfolio Summary', meta: `As of ${data.asOf}`,
      columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right' }],
      rows: [
        { metric: 'Total Loans', value: data.totalLoans }, { metric: 'Active Loans', value: data.activeLoans },
        { metric: 'Closed Loans', value: data.closedLoans }, { metric: 'Pending Loans', value: data.pendingLoans },
        { metric: 'Written Off Loans', value: data.writtenOffLoans }, { metric: 'Total Outstanding Balance', value: money(data.totalOutstandingBalance) },
        { metric: 'Average Loan Size', value: money(data.avgLoanSize) }, { metric: 'Disbursed This Month (count)', value: data.disbursedThisMonthCount },
        { metric: 'Disbursed This Month (amount)', value: money(data.disbursedThisMonthAmount) }, { metric: 'Disbursed Last Month (amount)', value: money(data.disbursedLastMonthAmount) },
        { metric: 'Loan Growth %', value: pct(data.loanGrowthPct) },
      ],
    }, `loan-portfolio-summary-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await loanReportsApi.pdf('portfolio-summary', { branchId, loanTypeId: loanTypeId || undefined }), `loan-portfolio-summary-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Loan Portfolio Summary" icon="coins"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
          <Field label="Loan Type">
            <Select value={loanTypeId} onChange={(e) => setLoanTypeId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">All Loan Types</option>
              {loanTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
          </Field>
        </div>
      </Card>
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

export function LoanParAgingScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [loanTypeId, setLoanTypeId] = useState<number | ''>('')

  useEffect(() => { loanTypesApi.list().then((t) => setLoanTypes(t.filter((x) => x.isProduct))).catch(() => setLoanTypes([])) }, [])

  useEffect(() => {
    setLoading(true)
    loanReportsApi.get('par-aging', { branchId, loanTypeId: loanTypeId || undefined }).then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [branchId, loanTypeId])

  const exportExcel = () => {
    if (!data) return
    void exportReportToExcel({
      sheetName: 'PAR Aging', reportTitle: 'Loan PAR / NPL Aging', meta: `As of ${data.asOf}`,
      sections: [
        {
          title: 'Summary',
          columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right' }],
          rows: [
            { metric: 'Total Active Portfolio', value: money(data.totalPortfolio) }, { metric: 'PAR 30 %', value: pct(data.par30Pct) },
            { metric: 'PAR 60 %', value: pct(data.par60Pct) }, { metric: 'NPL % (90+)', value: pct(data.nplPct) },
          ],
        },
        {
          title: 'Aging Buckets',
          columns: [{ label: 'Bucket', key: 'label' }, { label: 'Loans', key: 'count', align: 'right', format: 'number' }, { label: 'Outstanding', key: 'amount', align: 'right', format: 'money' }, { label: '% of Portfolio', key: 'pct', align: 'right', format: 'percent' }],
          rows: data.buckets || [],
        },
      ],
    }, `loan-par-aging-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await loanReportsApi.pdf('par-aging', { branchId, loanTypeId: loanTypeId || undefined }), `loan-par-aging-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Loan PAR / NPL Aging" icon="target"
        actions={data && <div className="row gap-3" style={{ alignItems: 'center' }}>
          <Badge tone={data.nplPct > 5 ? 'red' : 'green'} dot>NPL {pct(data.nplPct)}</Badge>
          <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />
        </div>} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
          <Field label="Loan Type">
            <Select value={loanTypeId} onChange={(e) => setLoanTypeId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">All Loan Types</option>
              {loanTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
          </Field>
        </div>
      </Card>
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

export function LoanArrearsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<OverdueLoan[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [loanTypeId, setLoanTypeId] = useState<number | ''>('')
  const [officers, setOfficers] = useState<{ id: number; name: string }[]>([])
  const [loanOfficerId, setLoanOfficerId] = useState<number | ''>('')

  useEffect(() => { loanTypesApi.list().then((t) => setLoanTypes(t.filter((x) => x.isProduct))).catch(() => setLoanTypes([])) }, [])
  useEffect(() => { api.users.marketingExecutives().then(setOfficers).catch(() => setOfficers([])) }, [])

  useEffect(() => {
    setLoading(true); setErr('')
    loanReportsApi.get('arrears', { from: fromDate || undefined, to: toDate || undefined, branchId, loanTypeId: loanTypeId || undefined, loanOfficerId: loanOfficerId || undefined })
      .then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, branchId, loanTypeId, loanOfficerId])

  const totalOverdue = rows.reduce((a, r) => a + Number(r.overdueAmount || 0), 0)
  const totalPenalty = rows.reduce((a, r) => a + Number(r.penaltyDue || 0), 0)
  const totalInstallments = rows.reduce((a, r) => a + Number(r.overdueInstallments || 0), 0)

  const byOfficer = useMemo(() => {
    const m = new Map<string, number>()
    for (const r of rows) {
      const name = r.loanDetail?.recoveryOfficer?.name || r.loanDetail?.loanOfficer?.name || 'Unassigned'
      m.set(name, (m.get(name) || 0) + Number(r.overdueAmount || 0) + Number(r.penaltyDue || 0))
    }
    return [...m.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8)
  }, [rows])
  const maxOfficer = Math.max(1, ...byOfficer.map(([, v]) => v))

  const arrearsColumns: ExcelColumn[] = [
    { label: 'Loan Code', key: 'code' }, { label: 'Customer', key: 'customer' }, { label: 'Branch', key: 'branch' }, { label: 'Officer', key: 'officer' },
    { label: 'Days Overdue', key: 'daysOverdue', align: 'right', format: 'number' }, { label: 'Installments', key: 'installments', align: 'right', format: 'number' },
    { label: 'Overdue Amount', key: 'overdueAmount', align: 'right', format: 'money' }, { label: 'Penalty Due', key: 'penaltyDue', align: 'right', format: 'money' },
  ]
  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Loan Arrears', meta: `Period: ${fromDate || '—'} to ${toDate || today()}`,
      columns: arrearsColumns,
      rows: rows.map((l) => ({
        code: l.code, customer: l.customer?.fullName || '—', branch: l.branch?.name || '—',
        officer: l.loanDetail?.recoveryOfficer?.name || l.loanDetail?.loanOfficer?.name || '—',
        daysOverdue: l.daysOverdue, installments: l.overdueInstallments, overdueAmount: Number(l.overdueAmount || 0), penaltyDue: Number(l.penaltyDue || 0),
      })),
      totalRow: { customer: 'Total', installments: totalInstallments, overdueAmount: totalOverdue, penaltyDue: totalPenalty },
      emptyText: 'No overdue loans',
    }, `loan-arrears-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await loanReportsApi.pdf('arrears', { from: fromDate || undefined, to: toDate || undefined, branchId, loanTypeId: loanTypeId || undefined, loanOfficerId: loanOfficerId || undefined }), `loan-arrears-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Loan Arrears" icon="alert"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
          <Field label="Loan Type">
            <Select value={loanTypeId} onChange={(e) => setLoanTypeId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">All Loan Types</option>
              {loanTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
          </Field>
          <Field label="Sales Executive">
            <Select value={loanOfficerId} onChange={(e) => setLoanOfficerId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">All Executives</option>
              {officers.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </Select>
          </Field>
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          <TileGrid>
            <Tile icon="alert" label="Overdue Loans" value={String(rows.length)} tone={rows.length > 0 ? 'red' : 'green'} />
            <Tile icon="list" label="Outstanding Installments" value={totalInstallments.toFixed(2)} tone="amber" />
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

export function LoanDisbursementScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [loanTypeId, setLoanTypeId] = useState<number | ''>('')

  useEffect(() => { loanTypesApi.list().then((t) => setLoanTypes(t.filter((x) => x.isProduct))).catch(() => setLoanTypes([])) }, [])

  useEffect(() => {
    setLoading(true); setErr('')
    loanReportsApi.get('disbursement', { from: fromDate || undefined, to: toDate || undefined, branchId, loanTypeId: loanTypeId || undefined })
      .then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, branchId, loanTypeId])

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

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Loan Disbursement', meta: `Period: ${fromDate || '—'} to ${toDate || today()}`,
      columns: [
        { label: 'Loan Code', key: 'code' }, { label: 'Customer', key: 'customer' }, { label: 'Branch', key: 'branch' }, { label: 'Loan Type', key: 'loanType' },
        { label: 'Officer', key: 'officer' }, { label: 'Principal', key: 'principal', align: 'right', format: 'money' }, { label: 'Disbursed', key: 'disbursed' }, { label: 'Status', key: 'status', align: 'center' },
        { label: 'Bank', key: 'bank' }, { label: 'Account Holder', key: 'accountHolder' }, { label: 'Account No', key: 'accountNo' },
      ],
      rows: rows.map((l) => ({
        code: l.code, customer: l.customer?.fullName || '—', branch: l.branch?.name || '—', loanType: l.loanDetail?.loanType?.name || '—',
        officer: l.loanDetail?.loanOfficer?.name || '—', principal: Number(l.principalAmount || 0), disbursed: dateOnly(l.disbursedAt), status: l.status,
        bank: l.bankDetail ? `${l.bankDetail.bank?.name || ''}${l.bankDetail.bankBranch ? ` ${l.bankDetail.bankBranch}` : ''}`.trim() : '—',
        accountHolder: l.bankDetail?.accountHolder || '—', accountNo: l.bankDetail?.accountNumber || '—',
      })),
      totalRow: { customer: 'Total', principal: total },
      emptyText: 'No disbursements in this range',
    }, `loan-disbursement-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await loanReportsApi.pdf('disbursement', { from: fromDate || undefined, to: toDate || undefined, branchId, loanTypeId: loanTypeId || undefined }), `loan-disbursement-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Loan Disbursement" icon="upload"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
          <Field label="Loan Type">
            <Select value={loanTypeId} onChange={(e) => setLoanTypeId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">All Loan Types</option>
              {loanTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
          </Field>
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
              { label: 'Bank' }, { label: 'Account Holder' }, { label: 'Account No' },
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
                <Td>{l.bankDetail ? `${l.bankDetail.bank?.name || ''}${l.bankDetail.bankBranch ? ` · ${l.bankDetail.bankBranch}` : ''}` : '—'}</Td>
                <Td>{l.bankDetail?.accountHolder || '—'}</Td>
                <Td mono>{l.bankDetail?.accountNumber || '—'}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}

/* ---------------------------- Closed Loans ---------------------------- */

export function LoanClosedLoansScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [loanTypeId, setLoanTypeId] = useState<number | ''>('')

  useEffect(() => { loanTypesApi.list().then((t) => setLoanTypes(t.filter((x) => x.isProduct))).catch(() => setLoanTypes([])) }, [])

  useEffect(() => {
    setLoading(true); setErr('')
    loanReportsApi.get('closed-loans', { from: fromDate || undefined, to: toDate || undefined, branchId, loanTypeId: loanTypeId || undefined })
      .then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, branchId, loanTypeId])

  const total = rows.reduce((a, r) => a + Number(r.principalAmount || 0), 0)

  const byType = useMemo(() => {
    const m = new Map<string, number>()
    for (const r of rows) m.set(r.loanDetail?.loanType?.name || 'Unknown', (m.get(r.loanDetail?.loanType?.name || 'Unknown') || 0) + 1)
    return [...m.entries()].map(([label, value], i) => ({ label, value, color: PALETTE[i % PALETTE.length] }))
  }, [rows])

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Closed Loans', meta: `Period: ${fromDate || '—'} to ${toDate || today()}`,
      columns: [
        { label: 'Loan Code', key: 'code' }, { label: 'Customer', key: 'customer' }, { label: 'Branch', key: 'branch' },
        { label: 'Loan Type', key: 'loanType' }, { label: 'Principal', key: 'principal', align: 'right', format: 'money' }, { label: 'Closed', key: 'closed' },
      ],
      rows: rows.map((l) => ({
        code: l.code, customer: l.customer?.fullName || '—', branch: l.branch?.name || '—', loanType: l.loanDetail?.loanType?.name || '—',
        principal: Number(l.principalAmount || 0), closed: dateOnly(l.closedAt),
      })),
      totalRow: { customer: 'Total', principal: total },
      emptyText: 'No closed loans in this range',
    }, `loan-closed-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await loanReportsApi.pdf('closed-loans', { from: fromDate || undefined, to: toDate || undefined, branchId, loanTypeId: loanTypeId || undefined }), `loan-closed-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Closed Loans" icon="check"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
          <Field label="Loan Type">
            <Select value={loanTypeId} onChange={(e) => setLoanTypeId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">All Loan Types</option>
              {loanTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
          </Field>
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

/* ---------------------------- Loan Detail Report ---------------------------- */

const LOAN_STATUSES: LoanStatus[] = ['Pending', 'Approved', 'Rejected', 'Cancelled', 'Active', 'Closed', 'WrittenOff', 'Restructured']
const DATE_FIELDS: { value: string; label: string }[] = [
  { value: 'createdAt', label: 'Created Date' },
  { value: 'applicationDate', label: 'Application Date' },
  { value: 'disbursedAt', label: 'Disbursed Date' },
  { value: 'closedAt', label: 'Closed Date' },
  { value: 'startDate', label: 'Start Date' },
]

interface LoanDetailFilters {
  status: LoanStatus | ''
  loanTypeId: number | ''
  loanOfficerId: number | ''
  recoveryOfficerId: number | ''
  customerSearch: string
  dateField: string
  fromDate: string
  toDate: string
  minPrincipal: string
  maxPrincipal: string
  overdueOnly: boolean
}
const EMPTY_DETAIL_FILTERS: LoanDetailFilters = {
  status: '', loanTypeId: '', loanOfficerId: '', recoveryOfficerId: '', customerSearch: '',
  dateField: 'createdAt', fromDate: '', toDate: '', minPrincipal: '', maxPrincipal: '', overdueOnly: false,
}

/** Excel columns for the full export — every field the backend flattens onto
 *  a loan-detail row (see LoanReportController::loanDetail()), one loan per row. */
const DETAIL_EXCEL_COLUMNS: ExcelColumn[] = [
  { label: 'Loan Code', key: 'code' }, { label: 'Status', key: 'status' },
  { label: 'Branch', key: 'branch' }, { label: 'Branch Code', key: 'branchCode' },
  { label: 'Customer Code', key: 'customerCode' }, { label: 'Customer Name', key: 'customerName' },
  { label: 'NIC No', key: 'nicNo' }, { label: 'Passport No', key: 'passportNo' }, { label: 'Phone', key: 'phone' },
  { label: 'Email', key: 'email' }, { label: 'Address', key: 'address' }, { label: 'Occupation', key: 'occupation' },
  { label: 'Monthly Income', key: 'monthlyIncome', align: 'right', format: 'money' },
  { label: 'Loan Type', key: 'loanType' }, { label: 'Loan Category', key: 'loanCategory' },
  { label: 'Principal', key: 'principal', align: 'right', format: 'money' },
  { label: 'Interest Rate %', key: 'interestRate', align: 'right', format: 'number' },
  { label: 'Rate Period', key: 'ratePeriod' }, { label: 'Term (Months)', key: 'termMonths', align: 'right', format: 'number' },
  { label: 'Frequency', key: 'frequency' }, { label: 'Interest Method', key: 'interestMethod' },
  { label: 'Down Payment', key: 'downPayment', align: 'right', format: 'money' }, { label: 'Purpose', key: 'purpose' },
  { label: 'Application Date', key: 'applicationDate' }, { label: 'Approved Date', key: 'approvedAt' }, { label: 'Approved By', key: 'approvedBy' },
  { label: 'Disbursed Date', key: 'disbursedAt' }, { label: 'Disbursed Amount', key: 'disbursedAmount', align: 'right', format: 'money' },
  { label: 'First Installment Date', key: 'firstInstallmentDate' }, { label: 'Start Date', key: 'startDate' },
  { label: 'Maturity Date', key: 'maturityDate' }, { label: 'Closed Date', key: 'closedAt' },
  { label: 'Loan Officer', key: 'loanOfficer' }, { label: 'Recovery Officer', key: 'recoveryOfficer' },
  { label: 'Reference Name', key: 'referenceName' }, { label: 'Reference ID No', key: 'referenceIdNo' }, { label: 'Reference Address', key: 'referenceAddress' },
  { label: 'Vehicle No', key: 'vehicleNo' }, { label: 'Guarantors', key: 'guarantors' }, { label: 'Co-Applicants', key: 'coApplicants' },
  { label: 'Collateral', key: 'collateral' }, { label: 'Collateral Value', key: 'collateralValue', align: 'right', format: 'money' },
  { label: 'Insurance Company', key: 'insuranceCompany' }, { label: 'Insurance Policy No', key: 'insurancePolicyNo' },
  { label: 'Insurance Amount', key: 'insuranceAmount', align: 'right', format: 'money' },
  { label: 'Charges Due', key: 'chargesDue', align: 'right', format: 'money' }, { label: 'Charges Paid', key: 'chargesPaid', align: 'right', format: 'money' },
  { label: 'Total Scheduled', key: 'totalScheduled', align: 'right', format: 'money' }, { label: 'Total Paid', key: 'totalPaid', align: 'right', format: 'money' },
  { label: 'Outstanding Balance', key: 'outstandingBalance', align: 'right', format: 'money' },
  { label: 'Days Overdue', key: 'daysOverdue', align: 'right', format: 'number' },
  { label: 'Overdue Amount', key: 'overdueAmount', align: 'right', format: 'money' }, { label: 'Penalty Due', key: 'penaltyDue', align: 'right', format: 'money' },
  { label: 'Rejected Reason', key: 'rejectedReason' }, { label: 'Cancelled Reason', key: 'cancelledReason' }, { label: 'Cancelled Date', key: 'cancelledAt' },
  { label: 'Created Date', key: 'createdAt' },
]

export function LoanDetailReportScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<LoanDetailReportRow[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [loaded, setLoaded] = useState(false)
  const [draft, setDraft] = useState<LoanDetailFilters>(EMPTY_DETAIL_FILTERS)
  const [applied, setApplied] = useState<LoanDetailFilters>(EMPTY_DETAIL_FILTERS)
  const { branches, branchId, setBranchId } = useBranchFilter(me)
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [officers, setOfficers] = useState<{ id: number; name: string }[]>([])

  useEffect(() => {
    loanTypesApi.list().then((t) => setLoanTypes(t.filter((x) => x.isProduct))).catch(() => setLoanTypes([]))
    api.users.marketingExecutives().then(setOfficers).catch(() => setOfficers([]))
  }, [])

  const runReport = (f: LoanDetailFilters, signal?: AbortSignal) => {
    setLoading(true); setErr(''); setLoaded(true)
    loanReportsApi.get('loan-detail', {
      branchId, status: f.status || undefined, loanTypeId: f.loanTypeId || undefined,
      loanOfficerId: f.loanOfficerId || undefined, recoveryOfficerId: f.recoveryOfficerId || undefined,
      customerSearch: f.customerSearch || undefined, dateField: f.dateField,
      from: f.fromDate || undefined, to: f.toDate || undefined,
      minPrincipal: f.minPrincipal || undefined, maxPrincipal: f.maxPrincipal || undefined,
      overdueOnly: f.overdueOnly || undefined,
    }, signal).then((d) => setRows(d || [])).catch((ex) => { if (ex?.code !== 'ERR_CANCELED') setErr(getErrorText(ex, 'Failed to load report')) }).finally(() => { if (!signal?.aborted) setLoading(false) })
  }

  // This is the heaviest report endpoint (full portfolio, no pagination —
  // needed whole for Excel export), so a quick branch/filter change while a
  // request is still in flight cancels it instead of leaving several full
  // scans running concurrently and racing to set state.
  useEffect(() => {
    const controller = new AbortController()
    runReport(applied, controller.signal)
    return () => controller.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [applied, branchId])

  const applyFilters = () => setApplied(draft)
  const resetFilters = () => { setDraft(EMPTY_DETAIL_FILTERS); setApplied(EMPTY_DETAIL_FILTERS); setBranchId(undefined) }

  const totalPrincipal = rows.reduce((a, r) => a + Number(r.principal || 0), 0)
  const totalOutstanding = rows.reduce((a, r) => a + Number(r.outstandingBalance || 0), 0)
  const overdueCount = rows.filter((r) => r.daysOverdue > 0).length

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Loan Detail Report',
      meta: `${rows.length} loan(s)${applied.fromDate || applied.toDate ? ` — ${DATE_FIELDS.find((d) => d.value === applied.dateField)?.label}: ${applied.fromDate || '—'} to ${applied.toDate || today()}` : ''}`,
      columns: DETAIL_EXCEL_COLUMNS,
      rows: rows as unknown as Record<string, unknown>[],
      totalRow: { customerName: 'Total', principal: totalPrincipal, outstandingBalance: totalOutstanding },
      emptyText: 'No loans match the selected filters',
    }, `loan-detail-report-${today()}.xlsx`)
  }

  return (
    <div>
      <PageHead crumbs="Loan Management / Reports" title="Loan Detail Report" icon="list"
        actions={loaded && !loading && <ExportButtons onExcel={exportExcel} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 14 }}>Loan &amp; Customer</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: '16px', marginBottom: 20 }}>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
          <Field label="Loan Type">
            <Select value={draft.loanTypeId} onChange={(e) => setDraft((s) => ({ ...s, loanTypeId: e.target.value ? Number(e.target.value) : '' }))}>
              <option value="">All Loan Types</option>
              {loanTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
          </Field>
          <Field label="Status">
            <Select value={draft.status} onChange={(e) => setDraft((s) => ({ ...s, status: e.target.value as LoanStatus | '' }))}>
              <option value="">All Statuses</option>
              {LOAN_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
            </Select>
          </Field>
          <Field label="Loan Officer">
            <Select value={draft.loanOfficerId} onChange={(e) => setDraft((s) => ({ ...s, loanOfficerId: e.target.value ? Number(e.target.value) : '' }))}>
              <option value="">All Officers</option>
              {officers.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </Select>
          </Field>
          <Field label="Recovery Officer">
            <Select value={draft.recoveryOfficerId} onChange={(e) => setDraft((s) => ({ ...s, recoveryOfficerId: e.target.value ? Number(e.target.value) : '' }))}>
              <option value="">All Officers</option>
              {officers.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </Select>
          </Field>
          <Field label="Customer" style={{ gridColumn: 'span 2' }}>
            <Input value={draft.customerSearch} onChange={(e) => setDraft((s) => ({ ...s, customerSearch: e.target.value }))} placeholder="Name, NIC, phone or code…" />
          </Field>
        </div>

        <div style={{ borderTop: '1px solid var(--line)', marginBottom: 20 }} />

        <div className="eyebrow" style={{ marginBottom: 14 }}>Date &amp; Amount Range</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: '16px', marginBottom: 20 }}>
          <Field label="Date Field">
            <Select value={draft.dateField} onChange={(e) => setDraft((s) => ({ ...s, dateField: e.target.value }))}>
              {DATE_FIELDS.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
            </Select>
          </Field>
          <DateRangeSelect from={draft.fromDate} to={draft.toDate} onChange={(f, t) => setDraft((s) => ({ ...s, fromDate: f, toDate: t }))} />
          <Field label="From"><Input type="date" value={draft.fromDate} onChange={(e) => setDraft((s) => ({ ...s, fromDate: e.target.value }))} /></Field>
          <Field label="To"><Input type="date" value={draft.toDate} onChange={(e) => setDraft((s) => ({ ...s, toDate: e.target.value }))} /></Field>
          <Field label="Min Principal"><Input type="number" placeholder="0" value={draft.minPrincipal} onChange={(e) => setDraft((s) => ({ ...s, minPrincipal: e.target.value }))} /></Field>
          <Field label="Max Principal"><Input type="number" placeholder="No limit" value={draft.maxPrincipal} onChange={(e) => setDraft((s) => ({ ...s, maxPrincipal: e.target.value }))} /></Field>
        </div>

        <div className="row gap-2" style={{ alignItems: 'center' }}>
          <Btn variant="primary" size="sm" icon="filter" onClick={applyFilters}>Apply Filters</Btn>
          <Btn variant="ghost" active={draft.overdueOnly} icon="alert" size="sm" onClick={() => setDraft((s) => ({ ...s, overdueOnly: !s.overdueOnly }))}>
            Overdue Only
          </Btn>
          <Btn variant="plain" size="sm" onClick={resetFilters}>Clear all</Btn>
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          <TileGrid>
            <Tile icon="coins" label="Loans" value={String(rows.length)} />
            <Tile icon="wallet" label="Total Principal" value={money(totalPrincipal)} />
            <Tile icon="wallet" label="Total Outstanding" value={money(totalOutstanding)} tone="amber" />
            <Tile icon="alert" label="Overdue Loans" value={String(overdueCount)} tone={overdueCount > 0 ? 'red' : 'green'} />
          </TileGrid>
          <Card pad={0}>
            <Table cols={[
              { label: 'Loan Code' }, { label: 'Customer' }, { label: 'NIC' }, { label: 'Branch' }, { label: 'Loan Type' },
              { label: 'Officer' }, { label: 'Principal', align: 'right' as const }, { label: 'Outstanding', align: 'right' as const },
              { label: 'Status', align: 'center' as const }, { label: 'Days Overdue', align: 'right' as const }, { label: 'Disbursed' },
            ]} rows={rows} empty="No loans match the selected filters"
              render={(l: LoanDetailReportRow) => <>
                <Td mono>{l.code}</Td>
                <Td>{l.customerName || '—'}</Td>
                <Td mono>{l.nicNo || '—'}</Td>
                <Td>{l.branch || '—'}</Td>
                <Td>{l.loanType || '—'}</Td>
                <Td>{l.loanOfficer || '—'}</Td>
                <Td align="right" mono>{money(l.principal)}</Td>
                <Td align="right" mono>{money(l.outstandingBalance)}</Td>
                <Td align="center"><Badge tone={l.status === 'Active' ? 'green' : l.status === 'Closed' ? 'neutral' : l.daysOverdue > 0 ? 'red' : 'neutral'} dot>{l.status}</Badge></Td>
                <Td align="right" mono>{l.daysOverdue > 0 ? l.daysOverdue : '—'}</Td>
                <Td>{dateOnly(l.disbursedAt)}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}
