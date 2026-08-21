/* Funding Management — Dashboard: six portfolio KPIs. Plain stat tiles (no
   trend/series data available yet), so no chart/legend is needed — see the
   dataviz skill's guidance that a single-number KPI is a stat tile, not a chart. */
import { useEffect, useState } from 'react'
import { Card, PageHead , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { fundingDashboardApi, type FundingDashboardSummary, type AuthUser } from '../api'
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

export function FundingDashboardScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<FundingDashboardSummary | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fundingDashboardApi.get().then(setData).catch(() => setData(null)).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingBlock />
  if (!data) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>Failed to load the funding dashboard.</div>

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Funding Dashboard" icon="pie" />
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 16 }}>
        <Tile icon="coins" label="Total Funding Portfolio" value={moneyK(Number(data.totalFundingPortfolio))} tone="blue" />
        <Tile icon="layers" label="Active Fixed Deposits" value={String(data.activeFds)} tone="green" />
        <Tile icon="wallet" label="Monthly Interest Payable" value={moneyK(Number(data.monthlyInterestPayable))} tone="amber" />
        <Tile icon="clock" label="Maturing This Month" value={String(data.maturingThisMonth)} tone="amber" />
        <Tile icon="wallet" label="New Deposits Today" value={moneyK(Number(data.newDepositsToday))} tone="green" />
        <Tile icon="swap" label="Renewals This Month" value={String(data.renewalsThisMonth)} tone="blue" />
      </div>
    </div>
  )
}

export default FundingDashboardScreen
