/* Customer base reports: two standalone screens (Summary, Activity), each
   its own nav entry and route — no tabs. Same shape as LoanReports.tsx. */
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Card, PageHead, Badge, Table, Td } from '../components/ui'
import { Icon } from '../components/Icon'
import { Donut, ProgressRow } from '../components/charts'
import { customerReportsApi, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

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
function Loading() { return <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> }
function ErrBox({ msg }: { msg: string }) { return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{msg}</div> }

/* ---------------------------- Summary ---------------------------- */

export function CustomerSummaryScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    customerReportsApi.get('summary').then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [])

  const statusData = data ? [
    { label: 'Active', value: data.activeCustomers, color: PALETTE[0] },
    { label: 'Inactive', value: data.inactiveCustomers, color: PALETTE[1] },
    { label: 'Blacklisted', value: data.blacklistedCustomers, color: PALETTE[2] },
  ].filter((d) => d.value > 0) : []

  return (
    <div>
      <PageHead crumbs="Customer Management / Reports" title="Customer Summary" icon="users" />
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

export function CustomerActivityScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    customerReportsApi.get('activity').then((d) => setRows(d || [])).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [])

  const topBorrowers = useMemo(() =>
    [...rows].sort((a, b) => Number(b.totalBorrowed) - Number(a.totalBorrowed)).slice(0, 8), [rows])
  const maxBorrowed = Math.max(1, ...topBorrowers.map((c) => Number(c.totalBorrowed || 0)))

  return (
    <div>
      <PageHead crumbs="Customer Management / Reports" title="Customer Activity" icon="list" />
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
              { label: 'Loans', align: 'right' as const }, { label: 'Borrowed', align: 'right' as const }, { label: 'Outstanding', align: 'right' as const },
              { label: 'FDs', align: 'right' as const }, { label: 'Deposited', align: 'right' as const }, { label: 'Joined' },
            ]} rows={rows} empty="No customers"
              render={(c: any) => <>
                <Td mono>{c.code}</Td>
                <Td>{c.fullName}</Td>
                <Td>{c.branch?.name || '—'}</Td>
                <Td align="center"><Badge tone={c.status === 'Active' ? 'green' : c.status === 'Blacklisted' ? 'red' : 'neutral'} dot>{c.status}</Badge></Td>
                <Td align="right" mono>{c.loanCount}</Td>
                <Td align="right" mono>{money(c.totalBorrowed)}</Td>
                <Td align="right" mono>{money(c.totalOutstanding)}</Td>
                <Td align="right" mono>{c.fdCount}</Td>
                <Td align="right" mono>{money(c.totalDeposited)}</Td>
                <Td>{dateOnly(c.joinedAt)}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}
