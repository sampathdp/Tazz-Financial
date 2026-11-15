/* Customer base reports: two standalone screens (Summary, Activity), each
   its own nav entry and route — no tabs. Same shape as LoanReports.tsx. */
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Card, PageHead, Badge, Btn, Input, Table, Td , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { ExportButtons } from '../components/ExportButtons'
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import { Donut, ProgressRow } from '../components/charts'
import { customerReportsApi, customersApi, type Customer, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportFlatTableToExcel } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v.toFixed(2)}%`)
const PALETTE = ['var(--ok)', 'var(--tx-3)', 'var(--bad)']

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

export function CustomerSummaryScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true)
    customerReportsApi.get('summary', { branchId }).then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [branchId])

  const statusData = data ? [
    { label: 'Active', value: data.activeCustomers, color: PALETTE[0] },
    { label: 'Inactive', value: data.inactiveCustomers, color: PALETTE[1] },
    { label: 'Blacklisted', value: data.blacklistedCustomers, color: PALETTE[2] },
  ].filter((d) => d.value > 0) : []

  const exportExcel = () => {
    if (!data) return
    void exportFlatTableToExcel({
      reportTitle: 'Customer Summary', meta: `As of ${data.asOf}`,
      columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right' }],
      rows: [
        { metric: 'Total Customers', value: data.totalCustomers }, { metric: 'Active Customers', value: data.activeCustomers },
        { metric: 'Inactive Customers', value: data.inactiveCustomers }, { metric: 'Blacklisted Customers', value: data.blacklistedCustomers },
        { metric: 'New This Month', value: data.newThisMonth }, { metric: 'New Last Month', value: data.newLastMonth },
        { metric: 'Growth %', value: pct(data.growthPct) }, { metric: 'Borrower Count', value: data.borrowerCount },
        { metric: 'Repeat Customer Count', value: data.repeatCustomerCount }, { metric: 'Repeat Customer %', value: pct(data.repeatCustomerPct) },
      ],
    }, `customer-summary-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await customerReportsApi.pdf('summary', { branchId }), `customer-summary-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Customer Management / Reports" title="Customer Summary" icon="users"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}><div className="row gap-3 wrap"><BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} /></div></Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : data && (
        <>
          <TileGrid>
            <Tile icon="users" label="Total Customers" value={String(data.totalCustomers ?? 0)} />
            <Tile icon="arrowUp" label="New This Month" value={String(data.newThisMonth ?? 0)} />
            <Tile icon="chart" label="Growth %" value={pct(data.growthPct)} tone={data.growthPct != null ? (data.growthPct >= 0 ? 'green' : 'red') : 'blue'} />
            <Tile icon="target" label="Repeat Customer %" value={pct(data.repeatCustomerPct)} />
          </TileGrid>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <ChartCard title="Customers by Status">
              {statusData.length > 0 ? <Donut data={statusData} center={data.totalCustomers} /> : <div className="t-3">No customers yet</div>}
            </ChartCard>
            <ChartCard title="Repeat vs First-time Borrowers">
              <ProgressRow label="Repeat customers (2+ loans)" value={data.repeatCustomerCount} max={Math.max(1, data.borrowerCount)} color="var(--ac-bright)" right={`${data.repeatCustomerCount} / ${data.borrowerCount}`} />
              <ProgressRow label="First-time borrowers" value={data.borrowerCount - data.repeatCustomerCount} max={Math.max(1, data.borrowerCount)} color="var(--tx-3)" right={String(data.borrowerCount - data.repeatCustomerCount)} />
            </ChartCard>
          </div>
        </>
      )}
    </div>
  )
}

/* ---------------------------- Activity ---------------------------- */

export function CustomerActivityScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true)
    customerReportsApi.get('activity', { branchId }).then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [branchId])

  const topBorrowers = useMemo(() =>
    [...rows].sort((a, b) => Number(b.totalBorrowed) - Number(a.totalBorrowed)).slice(0, 8), [rows])
  const maxBorrowed = Math.max(1, ...topBorrowers.map((c) => Number(c.totalBorrowed || 0)))

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Customer Activity',
      columns: [
        { label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'Branch', key: 'branch' }, { label: 'Status', key: 'status', align: 'center' },
        { label: 'Loans', key: 'loans', align: 'right', format: 'number' }, { label: 'Speed Drafts', key: 'speedDrafts', align: 'right', format: 'number' }, { label: 'Borrowed', key: 'borrowed', align: 'right', format: 'money' },
        { label: 'Outstanding', key: 'outstanding', align: 'right', format: 'money' }, { label: 'FDs', key: 'fds', align: 'right', format: 'number' },
        { label: 'Deposited', key: 'deposited', align: 'right', format: 'money' }, { label: 'Joined', key: 'joined' },
      ],
      rows: rows.map((c) => ({
        code: c.code, name: c.fullName, branch: c.branch?.name || '—', status: c.status, loans: c.loanCount, speedDrafts: c.speedDraftCount ?? 0, borrowed: Number(c.totalBorrowed || 0),
        outstanding: Number(c.totalOutstanding || 0), fds: c.fdCount, deposited: Number(c.totalDeposited || 0), joined: dateOnly(c.joinedAt),
      })),
      totalRow: { name: 'Total', borrowed: rows.reduce((a, c) => a + Number(c.totalBorrowed || 0), 0), outstanding: rows.reduce((a, c) => a + Number(c.totalOutstanding || 0), 0), deposited: rows.reduce((a, c) => a + Number(c.totalDeposited || 0), 0) },
      emptyText: 'No customers',
    }, `customer-activity-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await customerReportsApi.pdf('activity', { branchId }), `customer-activity-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Customer Management / Reports" title="Customer Activity" icon="list"
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}><div className="row gap-3 wrap"><BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} /></div></Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <>
          {topBorrowers.length > 0 && (
            <ChartCard title="Top Borrowers">
              {topBorrowers.map((c) => <ProgressRow key={c.id} label={c.fullName} value={Number(c.totalBorrowed || 0)} max={maxBorrowed} color="var(--ac-bright)" right={money(c.totalBorrowed)} />)}
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[
              { label: 'Code' }, { label: 'Name' }, { label: 'Branch' }, { label: 'Status', align: 'center' as const },
              { label: 'Loans', align: 'right' as const }, { label: 'Speed Drafts', align: 'right' as const }, { label: 'Borrowed', align: 'right' as const }, { label: 'Outstanding', align: 'right' as const },
              { label: 'FDs', align: 'right' as const }, { label: 'Deposited', align: 'right' as const }, { label: 'Joined' },
            ]} rows={rows} empty="No customers"
              render={(c: any) => <>
                <Td mono>{c.code}</Td>
                <Td>{c.fullName}</Td>
                <Td>{c.branch?.name || '—'}</Td>
                <Td align="center"><Badge tone={c.status === 'Active' ? 'green' : c.status === 'Blacklisted' ? 'red' : 'neutral'} dot>{c.status}</Badge></Td>
                <Td align="right" mono>{c.loanCount}</Td>
                <Td align="right" mono>{c.speedDraftCount ?? 0}</Td>
                <Td align="right" mono>{money(c.totalBorrowed)}</Td>
                <Td align="right" mono>{money(c.totalOutstanding)}</Td>
                <Td align="right" mono>{c.fdCount}</Td>
                <Td align="right" mono>{money(c.totalDeposited)}</Td>
                <Td>{dateOnly(c.joinedAt)}</Td>
              </>}
              footer={<tr style={{ fontWeight: 700, background: 'var(--bg-1)' }}><Td>Total</Td><Td /><Td /><Td /><Td align="right" mono>{rows.reduce((a: number, c: any) => a + Number(c.loanCount || 0), 0)}</Td><Td align="right" mono>{rows.reduce((a: number, c: any) => a + Number(c.speedDraftCount || 0), 0)}</Td><Td align="right" mono>{money(rows.reduce((a: number, c: any) => a + Number(c.totalBorrowed || 0), 0))}</Td><Td align="right" mono>{money(rows.reduce((a: number, c: any) => a + Number(c.totalOutstanding || 0), 0))}</Td><Td /><Td align="right" mono>{money(rows.reduce((a: number, c: any) => a + Number(c.totalDeposited || 0), 0))}</Td><Td /></tr>} />
          </Card>
        </>
      )}
    </div>
  )
}

/* ------------------------- Blacklisted ------------------------- */

export function CustomerBlacklistedScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true)
    customerReportsApi.get('blacklisted', { branchId }).then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [branchId])

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Blacklisted Customers',
      columns: [
        { label: 'Code', key: 'code' }, { label: 'Name', key: 'name' }, { label: 'NIC', key: 'nic' }, { label: 'Phone', key: 'phone' }, { label: 'Branch', key: 'branch' },
        { label: 'Reason', key: 'reason' }, { label: 'Blacklisted On', key: 'on' }, { label: 'By', key: 'by' }, { label: 'Outstanding', key: 'outstanding', align: 'right', format: 'money' },
      ],
      rows: rows.map((c) => ({
        code: c.code, name: c.fullName, nic: c.nicNo || '—', phone: c.phone || '—', branch: c.branch?.name || '—',
        reason: c.reason || '—', on: dateOnly(c.blacklistedAt), by: c.blacklistedBy || '—', outstanding: Number(c.totalOutstanding || 0),
      })),
      totalRow: { name: 'Total', outstanding: rows.reduce((a, c) => a + Number(c.totalOutstanding || 0), 0) },
      emptyText: 'No blacklisted customers',
    }, `blacklisted-customers-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await customerReportsApi.pdf('blacklisted', { branchId }), `blacklisted-customers-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Customer Management / Reports" title="Blacklisted Customers" icon="shield"
        sub="Barred from every new facility in every branch."
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}><div className="row gap-3 wrap"><BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} /></div></Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : (
        <Card pad={0}>
          <Table cols={[
            { label: 'Code' }, { label: 'Name' }, { label: 'NIC' }, { label: 'Branch' }, { label: 'Reason' },
            { label: 'Blacklisted On' }, { label: 'By' }, { label: 'Outstanding', align: 'right' as const },
          ]} rows={rows} empty="No blacklisted customers"
            render={(c: any) => <>
              <Td mono>{c.code}</Td>
              <Td>{c.fullName}</Td>
              <Td mono>{c.nicNo || '—'}</Td>
              <Td>{c.branch?.name || '—'}</Td>
              <Td>{c.reason || '—'}</Td>
              <Td>{dateOnly(c.blacklistedAt)}</Td>
              <Td>{c.blacklistedBy || '—'}</Td>
              <Td align="right" mono>{money(c.totalOutstanding)}</Td>
            </>}
            footer={<tr style={{ fontWeight: 700, background: 'var(--bg-1)' }}><Td>Total</Td><Td /><Td /><Td /><Td /><Td /><Td /><Td align="right" mono>{money(rows.reduce((a: number, c: any) => a + Number(c.totalOutstanding || 0), 0))}</Td></tr>} />
        </Card>
      )}
    </div>
  )
}

/* ------------------------- Credit Report ------------------------- */

/* Picker only — search a customer, then open the per-customer internal
   CRIB-style credit report at /customers/:id/credit-report (same page the
   customer detail screen links to). */
export function CustomerCreditReportScreen({ go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [rows, setRows] = useState<Customer[]>([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)

  const debounceRef = useRef<ReturnType<typeof setTimeout>>()
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => setDebounced(search.trim()), 300)
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current) }
  }, [search])

  useEffect(() => {
    if (!debounced) { setRows([]); setTotal(0); return }
    setLoading(true)
    customersApi.browse({ search: debounced, page: 1 })
      .then((r) => { setRows(r.data); setTotal(r.total) })
      .catch(() => { setRows([]); setTotal(0) })
      .finally(() => setLoading(false))
  }, [debounced])

  const open = (c: Customer) => go(`customers/${c.id}/credit-report`)

  return (
    <div>
      <PageHead crumbs="Customer Management / Reports" title="Customer Credit Report" icon="shield"
        sub="Internal, CRIB-style — payment profile, credit facilities and indicative score for one customer." />
      <Card style={{ marginBottom: 16 }}>
        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search customer by name, code, NIC or phone…" autoFocus />
      </Card>
      {loading ? <Loading /> : !debounced ? (
        <div className="t-3" style={{ padding: 40, textAlign: 'center', fontSize: 13 }}>Type to search for a customer.</div>
      ) : (
        <Card pad={0}>
          <Table cols={[
            { label: 'Code' }, { label: 'Name' }, { label: 'NIC' }, { label: 'Branch' },
            { label: 'Status', align: 'center' as const }, { label: '', align: 'right' as const },
          ]} rows={rows} empty="No matching customers" onRow={open}
            render={(c) => <>
              <Td mono>{c.code}</Td>
              <Td>{c.fullName}</Td>
              <Td mono>{c.nicNo || '—'}</Td>
              <Td>{c.branch?.name || '—'}</Td>
              <Td align="center"><Badge tone={c.status === 'Active' ? 'green' : c.status === 'Blacklisted' ? 'red' : 'neutral'} dot>{c.status}</Badge></Td>
              <Td align="right"><Btn variant="solid" size="sm" icon="chart" onClick={() => open(c)}>Open report</Btn></Td>
            </>} />
          {total > rows.length && (
            <div className="t-3" style={{ padding: '10px 14px', fontSize: 11.5 }}>Showing {rows.length} of {total} — refine the search to narrow results.</div>
          )}
        </Card>
      )}
    </div>
  )
}
