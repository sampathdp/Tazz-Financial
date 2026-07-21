/* Dashboard — Overview (API-backed) */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, statusTone } from '../components/ui'
import { Icon } from '../components/Icon'
import { AreaChart, Donut, Spark, ProgressRow, Gauge } from '../components/charts'
import { api } from '../api'
import type { AuthUser } from '../api'
import { moneyK } from '../data'

import type { Go } from './types'

function KPI({ icon, label, value, delta, up, spark, sparkColor, tone = 'blue' }: { icon: string; label: string; value: string; delta?: string; up?: boolean; spark?: number[]; sparkColor?: string; tone?: 'blue' | 'green' | 'amber' | 'red' }) {
  const tc = { blue: 'var(--ac-bright)', green: 'var(--ok)', amber: 'var(--warn)', red: 'var(--bad)' }[tone]
  const td = { blue: 'var(--ac-dim)', green: 'var(--ok-dim)', amber: 'var(--warn-dim)', red: 'var(--bad-dim)' }[tone]
  return (
    <Card pad={18} className="mms-card">
      <div className="row between" style={{ marginBottom: 14 }}>
        <div style={{ width: 38, height: 38, borderRadius: 10, background: td, display: 'grid', placeItems: 'center', color: tc }}><Icon n={icon} s={20} /></div>
        {delta != null && (
          <span className="row gap-1" style={{ fontSize: 12, fontWeight: 600, color: up ? 'var(--ok)' : 'var(--bad)', fontFamily: 'JetBrains Mono' }}>
            <Icon n={up ? 'arrowUp' : 'arrowDown'} s={13} />{delta}
          </span>
        )}
      </div>
      <div className="num" style={{ fontSize: 25, fontWeight: 700, fontFamily: 'Saira', letterSpacing: '-0.01em' }}>{value}</div>
      <div className="row between" style={{ marginTop: 4 }}>
        <span className="t-2" style={{ fontSize: 12 }}>{label}</span>
        {spark && spark.length > 1 && <Spark data={spark} color={sparkColor || tc} w={72} h={26} />}
      </div>
    </Card>
  )
}

function AttentionChip({ icon, label, count, urgent, onClick }: { icon: string; label: string; count: number; urgent?: boolean; onClick: () => void }) {
  const active = count > 0
  const tone = active ? (urgent ? 'var(--bad)' : 'var(--warn)') : 'var(--ok)'
  const dim = active ? (urgent ? 'var(--bad-dim)' : 'var(--warn-dim)') : 'var(--ok-dim)'
  return (
    <button onClick={onClick} className="row gap-3" style={{
      flex: '1 1 168px', textAlign: 'left', padding: '12px 14px', cursor: 'pointer',
      background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)', transition: 'all .15s var(--ease)',
    }}>
      <div style={{ width: 36, height: 36, borderRadius: 9, display: 'grid', placeItems: 'center', background: dim, color: tone, flexShrink: 0 }}>
        <Icon n={icon} s={18} />
      </div>
      <div style={{ minWidth: 0 }}>
        <div className="num" style={{ fontSize: 19, fontWeight: 700, fontFamily: 'Saira', color: active ? tone : 'var(--tx-0)' }}>{count}</div>
        <div className="t-2" style={{ fontSize: 11.5, whiteSpace: 'nowrap' }}>{label}</div>
      </div>
    </button>
  )
}

export default function DashScreen({ go, user }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.dashboard().then((d) => { setData(d); setLoading(false) }).catch(() => setLoading(false))
  }, [])

  if (loading) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading dashboard…</div>
  if (!data) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>Failed to load dashboard.</div>

  if (user?.role === 'salesrep') return <RepDashboard go={go} data={data} userName={user?.name} />
  return <AdminDashboard go={go} data={data} />
}

/** Scoped view for role=salesrep — their own numbers only, no approval queues or company-wide financials. */
function RepDashboard({ go, data, userName }: { go: Go; data: any; userName?: string }) {
  const kpis = data.kpis || {}
  const trend = (data.salesTrend || []) as { label: string; value: number }[]
  const recentInvoices = data.recentInvoices || []
  const topCustomers = data.topCustomers || []
  const openQuotations = data.openQuotations || 0

  const now = new Date()
  const weekday = now.toLocaleDateString('en-GB', { weekday: 'long' })
  const today = `${weekday}, ${String(now.getDate()).padStart(2, '0')}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getFullYear()).slice(-2)}`

  const target = kpis.target || 500000
  const achieved = kpis.achieved || 0
  const pct = Math.round(Math.min(100, (achieved / (target || 1)) * 100))

  return (
    <div>
      <PageHead crumbs="Overview" title="My Dashboard" sub={`${today}${userName ? ' · ' + userName : ''}`} />

      <div className="kpi-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 16, marginBottom: 16 }}>
        <KPI icon="coins" label="Sales · this month" value={moneyK(achieved)} tone="blue" spark={trend.slice(-6).map((t) => t.value)} />
        <KPI icon="chart" label="Sales · today" value={moneyK(kpis.salesToday || 0)} tone="green" />
        <KPI icon="wallet" label="Outstanding" value={moneyK(kpis.outstanding || 0)} tone="amber" />
        <KPI icon="doc" label="Open quotations" value={String(openQuotations)} tone="blue" />
      </div>

      <div className="dash-2col" style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, marginBottom: 16 }}>
        <Card>
          <div className="row between" style={{ marginBottom: 18 }}>
            <div><div className="eyebrow">Performance</div><h3 style={{ fontSize: 18, marginTop: 3 }}>Sales overview · 12 months</h3></div>
          </div>
          {trend.length > 0 && trend.some((t) => t.value > 0) ? (
            <AreaChart
              height={180}
              labels={trend.map((t) => t.label)}
              series={[{ data: trend.map((t) => t.value), color: 'var(--ac-bright)' }]}
              format={(v) => moneyK(v)}
            />
          ) : <div className="t-3" style={{ padding: 20 }}>No sales recorded yet</div>}
        </Card>
        <Card className="col" style={{ justifyContent: 'center' }}>
          <div className="eyebrow" style={{ marginBottom: 12 }}>Monthly target</div>
          <div className="row gap-4" style={{ alignItems: 'center' }}>
            <Gauge pct={pct} label="achieved" />
            <div className="col gap-2">
              <div><div className="num" style={{ fontSize: 20, fontWeight: 700, fontFamily: 'Saira' }}>{moneyK(achieved)}</div><div className="t-2" style={{ fontSize: 11.5 }}>of {moneyK(target)}</div></div>
              <div><div className="num" style={{ fontSize: 20, fontWeight: 700, fontFamily: 'Saira', color: (kpis.ordersCount || 0) > 0 ? 'var(--ok)' : 'var(--tx-0)' }}>{kpis.ordersCount || 0}</div><div className="t-2" style={{ fontSize: 11.5 }}>Invoices this month</div></div>
            </div>
          </div>
        </Card>
      </div>

      <div className="dash-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <Card>
          <div className="row between" style={{ marginBottom: 16 }}><h3 style={{ fontSize: 16 }}>Recent Invoices</h3><Btn variant="plain" size="sm" iconR="chev" onClick={() => go('dc/invoice')}>All</Btn></div>
          <div className="col gap-2">
            {recentInvoices.length === 0 && <div className="t-3" style={{ padding: 10 }}>No invoices yet</div>}
            {recentInvoices.map((i: any) => (
              <div key={i.id} className="row between mms-row" style={{ padding: '8px 10px', borderRadius: 8, background: 'var(--bg-0)' }}>
                <div style={{ minWidth: 0 }}>
                  <div style={{ fontSize: 13, fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{i.customer}</div>
                  <div className="mono t-3" style={{ fontSize: 11 }}>{i.code}</div>
                </div>
                <div style={{ textAlign: 'right', flexShrink: 0 }}>
                  <div className="num" style={{ fontSize: 12.5, fontWeight: 600 }}>{moneyK(i.total || 0)}</div>
                  <Badge tone={statusTone(i.status)}>{i.status}</Badge>
                </div>
              </div>
            ))}
          </div>
        </Card>
        <Card>
          <div className="row between" style={{ marginBottom: 16 }}><h3 style={{ fontSize: 16 }}>My Customers</h3><Btn variant="plain" size="sm" iconR="chev" onClick={() => go('m/customer')}>All</Btn></div>
          {topCustomers.length === 0 && <div className="t-3" style={{ padding: 10 }}>No customers yet</div>}
          {topCustomers.map((c: any) => (
            <ProgressRow key={c.id} label={c.name} value={c.outstanding || 0} max={c.limit || 1} color={(c.outstanding || 0) > (c.limit || 0) * 0.7 ? 'var(--bad)' : 'var(--ac)'} right={moneyK(c.outstanding || 0)} />
          ))}
        </Card>
      </div>
    </div>
  )
}

function AdminDashboard({ go, data }: { go: Go; data: any }) {
  const kpis = data.kpis || {}
  const lowStock = data.lowStock || []
  const topItems = data.topItems || []
  const reps = data.reps || []
  const trend = (data.salesTrend || []) as { label: string; value: number }[]
  const approvals = data.pendingApprovals || {}
  const recentInvoices = data.recentInvoices || []
  const topCustomers = data.topCustomers || []
  const openQuotations = data.openQuotations || 0

  const catPalette = ['var(--ac)', 'var(--ok)', 'var(--warn)', 'var(--info)', '#8b5cf6', '#ec4899', '#14b8a6', 'var(--tx-3)']
  const catData = ((data.stockByCategory || []) as { label: string; value: number | string }[])
    .map((c, i) => ({ label: c.label || 'Uncategorized', value: Number(c.value) || 0, color: catPalette[i % catPalette.length] }))
    .filter((c) => c.value > 0)

  const now = new Date()
  const weekday = now.toLocaleDateString('en-GB', { weekday: 'long' })
  const today = `${weekday}, ${String(now.getDate()).padStart(2, '0')}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getFullYear()).slice(-2)}`

  const trendTotal = trend.reduce((a, t) => a + (t.value || 0), 0)
  const activeMonths = trend.filter((t) => t.value > 0).length || 1

  const exportSummary = () => {
    // Implement generic export logic here or remove
  }

  return (
    <div>
      <PageHead crumbs="Overview" title="Dashboard" sub={`${today} · Main Store`}
        actions={<Btn variant="solid" size="md" icon="download" onClick={exportSummary}>Export</Btn>} />

      <div className="kpi-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 16, marginBottom: 16 }}>
        <KPI icon="coins" label="Sales · this month" value={moneyK(kpis.salesMonth || 0)} tone="blue" spark={trend.slice(-6).map((t) => t.value)} />
        <KPI icon="chart" label="Gross profit · this month" value={moneyK(kpis.grossProfit || 0)} tone="green" />
        <KPI icon="wallet" label="Receivables" value={moneyK(kpis.receivables || 0)} tone="amber" />
        <KPI icon="box" label="Stock value" value={moneyK(kpis.stockValue || 0)} tone="blue" />
      </div>

      <Card style={{ marginBottom: 16 }}>
        <div className="row between" style={{ marginBottom: 14 }}>
          <div><div className="eyebrow">Action center</div><h3 style={{ fontSize: 16, marginTop: 3 }}>Needs your attention</h3></div>
        </div>
        <div className="row gap-3 wrap">
          <AttentionChip icon="cart" label="POs awaiting approval" count={approvals.purchaseOrders || 0} urgent onClick={() => go('dc/po')} />

          <AttentionChip icon="truck" label="GRNs awaiting approval" count={approvals.grns || 0} urgent onClick={() => go('dc/grn')} />
          <AttentionChip icon="refund" label="Sales returns pending" count={approvals.salesReturns || 0} urgent onClick={() => go('dc/return')} />
          <AttentionChip icon="doc" label="Open quotations" count={openQuotations} onClick={() => go('dc/quote')} />
          <AttentionChip icon="alert" label="Stock alerts" count={(kpis.outOfStock || 0) + (kpis.lowStock || 0)} urgent onClick={() => go('m/item')} />
        </div>
      </Card>

      <div className="dash-2col" style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, marginBottom: 16 }}>
        <Card>
          <div className="row between" style={{ marginBottom: 18 }}>
            <div><div className="eyebrow">Performance</div><h3 style={{ fontSize: 18, marginTop: 3 }}>Sales overview · 12 months</h3></div>
          </div>
          {trend.length > 0 && trend.some((t) => t.value > 0) ? (
            <AreaChart
              height={180}
              labels={trend.map((t) => t.label)}
              series={[{ data: trend.map((t) => t.value), color: 'var(--ac-bright)' }]}
              format={(v) => moneyK(v)}
            />
          ) : <div className="t-3" style={{ padding: 20 }}>No sales recorded yet</div>}
          <div className="row between" style={{ marginTop: 16, paddingTop: 14, borderTop: '1px solid var(--line-soft)' }}>
            <div><div className="t-2" style={{ fontSize: 11.5 }}>12-month total</div><div className="num" style={{ fontWeight: 700, fontSize: 15 }}>{moneyK(trendTotal)}</div></div>
            <div><div className="t-2" style={{ fontSize: 11.5 }}>Avg / active month</div><div className="num" style={{ fontWeight: 700, fontSize: 15 }}>{moneyK(trendTotal / activeMonths)}</div></div>
            <div><div className="t-2" style={{ fontSize: 11.5 }}>Total SKUs</div><div className="num" style={{ fontWeight: 700, fontSize: 15 }}>{kpis.totalSkus || 0}</div></div>
          </div>
        </Card>
        <Card>
          <div className="eyebrow">Inventory mix</div><h3 style={{ fontSize: 18, marginTop: 3, marginBottom: 18 }}>Stock by Category</h3>
          {catData.length > 0 ? (
            <Donut center={catData.length} data={catData.slice(0, 6)} />
          ) : <div className="t-3" style={{ padding: 20 }}>No stock on hand</div>}
        </Card>
      </div>

      <div className="dash-3col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16, marginBottom: 16 }}>
        <Card>
          <div className="row between" style={{ marginBottom: 16 }}><h3 style={{ fontSize: 16 }}>Sales Reps</h3><Btn variant="plain" size="sm" iconR="chev" onClick={() => go('ad/reps')}>View</Btn></div>
          {reps.length === 0 && <div className="t-3" style={{ padding: 10 }}>No reps yet</div>}
          {reps.map((r: any) => <ProgressRow key={r.id} label={r.name} value={r.achieved || 0} max={r.target || 1} color={(r.achieved || 0) >= (r.target || 0) ? 'var(--ok)' : 'var(--ac)'} right={Math.round(((r.achieved || 0) / (r.target || 1)) * 100) + '%'} />)}
        </Card>
        <Card>
          <div className="row between" style={{ marginBottom: 16 }}><h3 style={{ fontSize: 16 }}>Recent Invoices</h3><Btn variant="plain" size="sm" iconR="chev" onClick={() => go('dc/invoice')}>All</Btn></div>
          <div className="col gap-2">
            {recentInvoices.length === 0 && <div className="t-3" style={{ padding: 10 }}>No invoices yet</div>}
            {recentInvoices.map((i: any) => (
              <div key={i.id} className="row between mms-row" style={{ padding: '8px 10px', borderRadius: 8, background: 'var(--bg-0)' }}>
                <div style={{ minWidth: 0 }}>
                  <div style={{ fontSize: 13, fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{i.customer}</div>
                  <div className="mono t-3" style={{ fontSize: 11 }}>{i.code}</div>
                </div>
                <div style={{ textAlign: 'right', flexShrink: 0 }}>
                  <div className="num" style={{ fontSize: 12.5, fontWeight: 600 }}>{moneyK(i.total || 0)}</div>
                  <Badge tone={statusTone(i.status)}>{i.status}</Badge>
                </div>
              </div>
            ))}
          </div>
        </Card>
        <Card>
          <div className="row between" style={{ marginBottom: 16 }}><h3 style={{ fontSize: 16 }}>Top Outstanding</h3><Btn variant="plain" size="sm" iconR="chev" onClick={() => go('m/customer')}>All</Btn></div>
          {topCustomers.length === 0 && <div className="t-3" style={{ padding: 10 }}>No customers yet</div>}
          {topCustomers.map((c: any) => (
            <ProgressRow key={c.id} label={c.name} value={c.outstanding || 0} max={c.limit || 1} color={(c.outstanding || 0) > (c.limit || 0) * 0.7 ? 'var(--bad)' : 'var(--ac)'} right={moneyK(c.outstanding || 0)} />
          ))}
        </Card>
      </div>

      <div className="dash-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <Card>
          <div className="row between" style={{ marginBottom: 16 }}><h3 style={{ fontSize: 16 }}>Low / Out of Stock</h3><Badge tone="red">{lowStock.length} alerts</Badge></div>
          <div className="col gap-2">
            {lowStock.length === 0 && <div className="t-3" style={{ padding: 10 }}>All stock OK</div>}
            {lowStock.map((i: any) => (
              <div key={i.id} className="row between mms-row" style={{ padding: '8px 10px', borderRadius: 8, background: 'var(--bg-0)' }}>
                <div style={{ minWidth: 0 }}>
                  <div style={{ fontSize: 13, fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{i.name}</div>
                  <div className="mono t-3" style={{ fontSize: 11 }}>{i.code}</div>
                </div>
                <Badge tone={i.status === 'out' ? 'red' : 'amber'}>{i.qty} {i.unit}</Badge>
              </div>
            ))}
          </div>
        </Card>
        <Card>
          <div className="row between" style={{ marginBottom: 16 }}><h3 style={{ fontSize: 16 }}>Top Value Items</h3><Btn variant="plain" size="sm" iconR="chev" onClick={() => go('m/item')}>All</Btn></div>
          {topItems.length === 0 && <div className="t-3" style={{ padding: 10 }}>No items yet</div>}
          {topItems.map((i: any, n: number) => (
            <div key={i.id} className="row gap-3" style={{ padding: '8px 0', borderBottom: n < topItems.length - 1 ? '1px solid var(--line-soft)' : 'none' }}>
              <span className="num t-3" style={{ fontSize: 13, width: 18 }}>{n + 1}</span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13, fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{i.name}</div>
                <div className="t-3" style={{ fontSize: 11 }}>{i.brand} · {i.category}</div>
              </div>
              <span className="num" style={{ fontSize: 12.5, fontWeight: 600 }}>{moneyK((i.price || 0) * (i.qty || 0))}</span>
            </div>
          ))}
        </Card>
      </div>
    </div>
  )
}
