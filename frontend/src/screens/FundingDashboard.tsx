/* Funding Management — Dashboard: portfolio KPIs, FD charts (interest payable vs
   paid, maturity ladder, portfolio by product) and the list of every FD interest
   installment due up to the current date (overdue + today). */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Table, Td, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { BarChart, Donut } from '../components/charts'
import { fundingDashboardApi, type FundingDashboardSummary, type AuthUser } from '../api'
import { moneyK } from '../data'
import type { Go } from './types'

const money = (v: number) => Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const PALETTE = ['#4f8cff', '#2fbf8a', '#f5a524', '#a06bff', '#ef5b5b', '#2ec4d6']

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

function Legend({ items }: { items: { label: string; color: string }[] }) {
  return (
    <div className="row gap-4" style={{ fontSize: 12, marginBottom: 8 }}>
      {items.map((i) => (
        <span key={i.label} className="row gap-2" style={{ alignItems: 'center' }}>
          <span style={{ width: 9, height: 9, borderRadius: 2, background: i.color }} />
          <span className="t-2">{i.label}</span>
        </span>
      ))}
    </div>
  )
}

export function FundingDashboardScreen({ go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<FundingDashboardSummary | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fundingDashboardApi.get().then(setData).catch(() => setData(null)).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingBlock />
  if (!data) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>Failed to load the funding dashboard.</div>

  const dueDate = data.interestDueAsOf
  const productTotal = data.portfolioByProduct.reduce((s, p) => s + p.value, 0)

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Funding Dashboard" icon="pie" />

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 16 }}>
        <Tile icon="coins" label="Total Funding Portfolio" value={moneyK(Number(data.totalFundingPortfolio))} tone="blue" />
        <Tile icon="layers" label="Active Fixed Deposits" value={String(data.activeFds)} tone="green" />
        <Tile icon="wallet" label="Interest Due Till Today" value={moneyK(Number(data.interestDueTotal))} tone={data.interestDueCount ? 'red' : 'green'} />
        <Tile icon="wallet" label="Monthly Interest Payable" value={moneyK(Number(data.monthlyInterestPayable))} tone="amber" />
        <Tile icon="clock" label="Maturing This Month" value={String(data.maturingThisMonth)} tone="amber" />
        <Tile icon="wallet" label="New Deposits Today" value={moneyK(Number(data.newDepositsToday))} tone="green" />
        <Tile icon="swap" label="Renewals This Month" value={String(data.renewalsThisMonth)} tone="blue" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(380px, 1fr))', gap: 16, marginTop: 16 }}>
        <Card pad={18}>
          <div style={{ fontWeight: 600, marginBottom: 4 }}>Interest — Payable vs Paid</div>
          <div className="t-2" style={{ fontSize: 12, marginBottom: 10 }}>Current month + next 5 (payable = pending installments)</div>
          <Legend items={[{ label: 'Payable', color: PALETTE[2] }, { label: 'Paid', color: PALETTE[1] }]} />
          <BarChart data={data.interestByMonth} keys={['payable', 'paid']} colors={[PALETTE[2], PALETTE[1]]} format={(v) => moneyK(v).replace('Rs ', '')} />
        </Card>
        <Card pad={18}>
          <div style={{ fontWeight: 600, marginBottom: 4 }}>Maturity Ladder</div>
          <div className="t-2" style={{ fontSize: 12, marginBottom: 10 }}>Principal maturing per month</div>
          <Legend items={[{ label: 'Principal', color: PALETTE[0] }]} />
          <BarChart data={data.maturityByMonth} keys={['amount']} colors={[PALETTE[0]]} format={(v) => moneyK(v).replace('Rs ', '')} />
        </Card>
        <Card pad={18}>
          <div style={{ fontWeight: 600, marginBottom: 10 }}>Portfolio by Product</div>
          {productTotal > 0
            ? <Donut data={data.portfolioByProduct.map((p, i) => ({ ...p, color: PALETTE[i % PALETTE.length] }))} center={data.activeFds} />
            : <div className="t-2" style={{ padding: 30, textAlign: 'center' }}>No active fixed deposits.</div>}
        </Card>
      </div>

      <Card pad={0} style={{ marginTop: 16 }}>
        <div className="row between wrap gap-2" style={{ padding: '14px 16px', alignItems: 'center' }}>
          <div className="row gap-2" style={{ alignItems: 'center' }}>
            <div style={{ fontWeight: 600 }}>FD Interest Due — as of {dueDate}</div>
            <Badge tone={data.interestDueCount ? 'red' : 'green'} dot>
              {data.interestDueCount} due{data.interestDueOverdueCount ? ` · ${data.interestDueOverdueCount} overdue` : ''}
            </Badge>
          </div>
          <div className="row gap-3" style={{ alignItems: 'center' }}>
            <span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(data.interestDueTotal)}</span>
            <Btn size="sm" variant="primary" icon="check" onClick={() => go('fund/interest-due')}>Open Interest Due</Btn>
          </div>
        </div>
        <Table
          cols={[{ label: 'FD No' }, { label: 'Customer' }, { label: '#', align: 'center' }, { label: 'Due Date' }, { label: 'Status', align: 'center' }, { label: 'Amount', align: 'right' }]}
          rows={data.interestDueItems} empty="No FD interest due up to today"
          render={(i: FundingDashboardSummary['interestDueItems'][number]) => <>
            <Td mono>{i.fdNumber}</Td>
            <Td>{i.customerName || '—'}</Td>
            <Td align="center">{i.installmentNo}</Td>
            <Td>{i.dueDate}</Td>
            <Td align="center"><Badge tone={i.dueDate < dueDate ? 'red' : 'amber'} dot>{i.dueDate < dueDate ? 'Overdue' : 'Due Today'}</Badge></Td>
            <Td align="right" mono>{money(i.amount)}</Td>
          </>} />
      </Card>
    </div>
  )
}

export default FundingDashboardScreen
