/* Dashboard — top-level KPIs (route id 'dash'). Every figure comes straight
   from DashboardController::index(), which aggregates the same tables the
   dedicated Loan/Collection/Accounting/Funding reports use, so nothing here
   can disagree with a report a user drills into. Plain stat tiles, no
   trend/series data behind these numbers yet — see the dataviz skill's
   guidance that a single-number KPI is a stat tile, not a chart. */
import { useEffect, useState, type ReactNode } from 'react'
import { Card, PageHead } from '../components/ui'
import { Icon } from '../components/Icon'
import { api, type DashboardSummary, type AuthUser } from '../api'
import { moneyK } from '../data'
import type { Go } from './types'

function Tile({ icon, label, value, tone = 'blue' }: { icon: string; label: string; value: string; tone?: 'blue' | 'green' | 'amber' | 'red' }) {
  const tc = { blue: 'var(--ac-bright)', green: 'var(--ok)', amber: 'var(--warn)', red: 'var(--bad)' }[tone]
  const td = { blue: 'var(--ac-dim)', green: 'var(--ok-dim)', amber: 'var(--warn-dim)', red: 'var(--bad-dim)' }[tone]
  return (
    <Card pad={18}>
      <div style={{ width: 38, height: 38, borderRadius: 10, background: td, display: 'grid', placeItems: 'center', color: tc, marginBottom: 14 }}>
        <Icon n={icon} s={20} />
      </div>
      <div className="num" style={{ fontSize: 25, fontWeight: 700, fontFamily: 'Saira', letterSpacing: '-0.01em' }}>{value}</div>
      <div className="t-2" style={{ fontSize: 12, marginTop: 4 }}>{label}</div>
    </Card>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div style={{ marginBottom: 16 }}>
      <div className="eyebrow" style={{ marginBottom: 10 }}>{title}</div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 16 }}>{children}</div>
    </div>
  )
}

export default function DashScreen({ go: _go, user: _user }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<DashboardSummary | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.dashboard().then((d) => { setData(d); setLoading(false) }).catch(() => setLoading(false))
  }, [])

  if (loading) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading dashboard…</div>
  if (!data) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>Failed to load dashboard.</div>

  const now = new Date()
  const weekday = now.toLocaleDateString('en-GB', { weekday: 'long' })
  const today = `${weekday}, ${String(now.getDate()).padStart(2, '0')}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getFullYear()).slice(-2)}`

  return (
    <div>
      <PageHead crumbs="Overview" title="Dashboard" sub={today} />

      <Section title="Loan Portfolio">
        <Tile icon="coins" label="Total Loan Portfolio" value={moneyK(data.totalLoanPortfolio)} tone="blue" />
        <Tile icon="wallet" label="Outstanding Loan Balance" value={moneyK(data.outstandingLoanBalance)} tone="blue" />
        <Tile icon="alert" label="Arrears Amount" value={moneyK(data.arrearsAmount)} tone={data.arrearsAmount > 0 ? 'red' : 'green'} />
        <Tile icon="shield" label="Default Loans (90+ days)" value={String(data.defaultLoans)} tone={data.defaultLoans > 0 ? 'red' : 'green'} />
      </Section>

      <Section title="Collections & Profit">
        <Tile icon="chart" label="Today's Collections" value={moneyK(data.todaysCollections)} tone="green" />
        <Tile icon="coins" label="This Month Income" value={moneyK(data.monthIncome)} tone="green" />
        <Tile icon="wallet" label="This Month Expenses" value={moneyK(data.monthExpense)} tone="amber" />
        <Tile icon="pie" label="Net Profit" value={moneyK(data.netProfit)} tone={data.netProfit >= 0 ? 'green' : 'red'} />
      </Section>

      <Section title="Balance Sheet & Liquidity">
        <Tile icon="layers" label="Total Assets" value={moneyK(data.totalAssets)} tone="blue" />
        <Tile icon="building" label="Total Liabilities" value={moneyK(data.totalLiabilities)} tone="amber" />
        <Tile icon="wallet" label="Total Deposits" value={moneyK(data.totalDeposits)} tone="blue" />
        <Tile icon="building" label="Bank Balances" value={moneyK(data.bankBalances)} tone="blue" />
        <Tile icon="coins" label="Cash in Hand" value={moneyK(data.cashInHand)} tone="blue" />
      </Section>

      <Section title="Organization">
        <Tile icon="users" label="Active Customers" value={String(data.activeCustomers)} tone="blue" />
        <Tile icon="users" label="Active Loan Officers" value={String(data.activeLoanOfficers)} tone="blue" />
      </Section>
    </div>
  )
}
