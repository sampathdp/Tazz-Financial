/* Investor-centric reports: two standalone screens (Summary, Directory),
   each its own nav entry and route — no tabs. Customers who hold a fixed
   deposit, viewed by investor rather than by FD product (that's Funding
   Reports). Same shape as LoanReports.tsx. */
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Card, PageHead, Table, Td } from '../components/ui'
import { Icon } from '../components/Icon'
import { ExportButtons } from '../components/ExportButtons'
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import { ProgressRow } from '../components/charts'
import { investorReportsApi, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportFlatTableToExcel } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')

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

/* ---------------------------- Summary ---------------------------- */

export function InvestorSummaryScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true)
    investorReportsApi.get('summary', { branchId }).then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [branchId])

  const exportExcel = () => {
    if (!data) return
    void exportFlatTableToExcel({
      reportTitle: 'Investor Summary', meta: `As of ${data.asOf}`,
      columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right' }],
      rows: [
        { metric: 'Investor Count', value: data.investorCount }, { metric: 'Active Investor Count', value: data.activeInvestorCount },
        { metric: 'Total Invested', value: money(data.totalInvested) }, { metric: 'Average Investment', value: money(data.avgInvestment) },
        { metric: 'New Investors This Month', value: data.newInvestorsThisMonth },
      ],
    }, `investor-summary-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await investorReportsApi.pdf('summary', { branchId }), `investor-summary-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Funding / Investment / Reports" title="Investor Summary" icon="pie"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}><div className="row gap-3 wrap"><BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} /></div></Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : data && (
        <TileGrid>
          <Tile icon="users" label="Investors" value={String(data.investorCount ?? 0)} />
          <Tile icon="users" label="Active Investors" value={String(data.activeInvestorCount ?? 0)} tone="green" />
          <Tile icon="coins" label="Total Invested" value={money(data.totalInvested)} />
          <Tile icon="target" label="Avg Investment" value={money(data.avgInvestment)} />
          <Tile icon="arrowUp" label="New This Month" value={String(data.newInvestorsThisMonth ?? 0)} />
        </TileGrid>
      )}
    </div>
  )
}

/* ---------------------------- Directory ---------------------------- */

export function InvestorDirectoryScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true)
    investorReportsApi.get('directory', { branchId }).then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [branchId])

  const topInvestors = useMemo(() =>
    [...rows].sort((a, b) => Number(b.totalPrincipal) - Number(a.totalPrincipal)).slice(0, 8), [rows])
  const maxPrincipal = Math.max(1, ...topInvestors.map((c) => Number(c.totalPrincipal || 0)))

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Investor Directory',
      columns: [
        { label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'Branch', key: 'branch' }, { label: 'Phone', key: 'phone' },
        { label: 'FDs', key: 'fds', align: 'right', format: 'number' }, { label: 'Active FDs', key: 'activeFds', align: 'right', format: 'number' },
        { label: 'Total Principal', key: 'principal', align: 'right', format: 'money' }, { label: 'Interest Paid', key: 'interestPaid', align: 'right', format: 'money' }, { label: 'Last Deposit', key: 'lastDeposit' },
      ],
      rows: rows.map((c) => ({
        code: c.code, name: c.fullName, branch: c.branch?.name || '—', phone: c.phone || '—', fds: c.fdCount, activeFds: c.activeFdCount,
        principal: Number(c.totalPrincipal || 0), interestPaid: Number(c.totalInterestPaid || 0), lastDeposit: dateOnly(c.lastDepositAt),
      })),
      totalRow: { name: 'Total', principal: rows.reduce((a, c) => a + Number(c.totalPrincipal || 0), 0), interestPaid: rows.reduce((a, c) => a + Number(c.totalInterestPaid || 0), 0) },
      emptyText: 'No investors',
    }, `investor-directory-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await investorReportsApi.pdf('directory', { branchId }), `investor-directory-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Funding / Investment / Reports" title="Investor Directory" icon="list"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}><div className="row gap-3 wrap"><BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} /></div></Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          {topInvestors.length > 0 && (
            <ChartCard title="Top Investors by Principal">
              {topInvestors.map((c) => <ProgressRow key={c.id} label={c.fullName} value={Number(c.totalPrincipal || 0)} max={maxPrincipal} color="var(--ac-bright)" right={money(c.totalPrincipal)} />)}
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[
              { label: 'Code' }, { label: 'Name' }, { label: 'Branch' }, { label: 'Phone' },
              { label: 'FDs', align: 'right' as const }, { label: 'Active FDs', align: 'right' as const },
              { label: 'Total Principal', align: 'right' as const }, { label: 'Interest Paid', align: 'right' as const }, { label: 'Last Deposit' },
            ]} rows={rows} empty="No investors"
              render={(c: any) => <>
                <Td mono>{c.code}</Td>
                <Td>{c.fullName}</Td>
                <Td>{c.branch?.name || '—'}</Td>
                <Td>{c.phone || '—'}</Td>
                <Td align="right" mono>{c.fdCount}</Td>
                <Td align="right" mono>{c.activeFdCount}</Td>
                <Td align="right" mono>{money(c.totalPrincipal)}</Td>
                <Td align="right" mono>{money(c.totalInterestPaid)}</Td>
                <Td>{dateOnly(c.lastDepositAt)}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}
