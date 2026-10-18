/* Dashboard — executive overview (route id 'dash'). Every figure comes from
   DashboardController::index(), which aggregates the same tables the
   dedicated Loan/Collection/Accounting/Funding reports use, so nothing here
   can disagree with a report a user drills into. Charts are plain SVG with a
   shared hover tooltip; palette is categorical and validated for both themes. */
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Card, PageHead } from '../components/ui'
import { Icon } from '../components/Icon'
import { Donut, Gauge, Spark } from '../components/charts'
import { api, type DashboardSummary, type AuthUser } from '../api'
import { moneyK } from '../data'
import type { Go } from './types'

const C = {
  blue: 'var(--ac-bright)',
  green: 'var(--ok)',
  amber: 'var(--warn)',
  red: 'var(--bad)',
  violet: 'oklch(0.68 0.16 290)',
  teal: 'oklch(0.7 0.12 195)',
  grey: 'var(--tx-3)',
}
const CAT = [C.blue, C.teal, C.violet, C.amber, C.green, C.red, C.grey]
const axis = { fontSize: 10.5, fill: 'var(--tx-3)', fontFamily: 'JetBrains Mono' } as const
const short = (n: number) => {
  const a = Math.abs(n)
  if (a >= 1e9) return (n / 1e9).toFixed(1) + 'B'
  if (a >= 1e6) return (n / 1e6).toFixed(1) + 'M'
  if (a >= 1e3) return (n / 1e3).toFixed(0) + 'K'
  return String(Math.round(n))
}
const pct = (a: number, b: number) => (b > 0 ? (a / b) * 100 : 0)

/* ---------- layout helpers ---------- */

function Panel({ title, sub, right, children, style }: { title: string; sub?: string; right?: ReactNode; children: ReactNode; style?: React.CSSProperties }) {
  return (
    <Card pad={18} style={style}>
      <div className="row between" style={{ alignItems: 'flex-start', marginBottom: 14, gap: 12 }}>
        <div>
          <div style={{ fontSize: 14, fontWeight: 600 }}>{title}</div>
          {sub && <div className="t-2" style={{ fontSize: 11.5, marginTop: 2 }}>{sub}</div>}
        </div>
        {right}
      </div>
      {children}
    </Card>
  )
}

function Grid({ cols, children }: { cols: string; children: ReactNode }) {
  return <div style={{ display: 'grid', gridTemplateColumns: cols, gap: 16, marginBottom: 16 }}>{children}</div>
}

function Legend({ items }: { items: { label: string; color: string }[] }) {
  return (
    <div className="row gap-3" style={{ fontSize: 11.5, flexWrap: 'wrap' }}>
      {items.map((i) => (
        <span key={i.label} className="row gap-1 t-2" style={{ alignItems: 'center' }}>
          <span style={{ width: 9, height: 9, borderRadius: 2, background: i.color }} />{i.label}
        </span>
      ))}
    </div>
  )
}

function Kpi({ icon, label, value, tone, series, note }: { icon: string; label: string; value: string; tone: keyof typeof C; series?: number[]; note?: ReactNode }) {
  return (
    <Card pad={16}>
      <div className="row between" style={{ alignItems: 'center' }}>
        <div className="t-2" style={{ fontSize: 12 }}>{label}</div>
        <span style={{ color: C[tone] }}><Icon n={icon} s={18} /></span>
      </div>
      <div className="num" style={{ fontSize: 24, fontWeight: 700, fontFamily: 'Saira', margin: '8px 0 6px', letterSpacing: '-0.01em' }}>{value}</div>
      <div className="row between" style={{ alignItems: 'flex-end', minHeight: 30 }}>
        <div className="t-2" style={{ fontSize: 11.5 }}>{note}</div>
        {series && series.length > 1 && <Spark data={series} color={C[tone]} w={84} h={30} />}
      </div>
    </Card>
  )
}

/* ---------- chart primitives with hover tooltip ---------- */

function useWidth() {
  const ref = useRef<HTMLDivElement>(null)
  const [w, setW] = useState(600)
  useEffect(() => {
    const el = ref.current
    if (!el) return
    const ro = new ResizeObserver(() => setW(el.clientWidth || 600))
    ro.observe(el); setW(el.clientWidth || 600)
    return () => ro.disconnect()
  }, [])
  return [ref, w] as const
}

type Row = { label: string; values: { name: string; value: number; color: string }[] }

function Tip({ x, w, row }: { x: number; w: number; row: Row }) {
  const left = x > w / 2
  return (
    <div style={{ position: 'absolute', top: 6, [left ? 'right' : 'left']: left ? w - x + 10 : x + 10, pointerEvents: 'none', background: 'var(--bg-2)', border: '1px solid var(--line)', borderRadius: 8, padding: '8px 10px', fontSize: 12, boxShadow: 'var(--sh-1)', zIndex: 2, whiteSpace: 'nowrap' }}>
      <div style={{ fontWeight: 600, marginBottom: 4 }}>{row.label}</div>
      {row.values.map((v) => (
        <div key={v.name} className="row gap-2" style={{ alignItems: 'center', justifyContent: 'space-between' }}>
          <span className="row gap-1 t-2" style={{ alignItems: 'center' }}><span style={{ width: 8, height: 8, borderRadius: 2, background: v.color }} />{v.name}</span>
          <span className="mono" style={{ marginLeft: 14 }}>{moneyK(v.value)}</span>
        </div>
      ))}
    </div>
  )
}

/** Grouped bars or lines over a shared category axis, hover shows every series for that column. */
function TimeChart({ labels, series, kind, height = 240, zeroLine }: {
  labels: string[]; series: { name: string; data: number[]; color: string }[]; kind: 'bar' | 'line'; height?: number; zeroLine?: boolean
}) {
  const [ref, W] = useWidth()
  const [hi, setHi] = useState<number | null>(null)
  const pad = { l: 44, r: 10, t: 10, b: 24 }
  const H = height
  const all = series.flatMap((s) => s.data)
  const rawMax = Math.max(0, ...all), rawMin = Math.min(0, ...all)
  const max = rawMax === 0 && rawMin === 0 ? 1 : rawMax * 1.12
  const min = rawMin < 0 ? rawMin * 1.12 : 0
  const Y = (v: number) => pad.t + (1 - (v - min) / (max - min)) * (H - pad.t - pad.b)
  const n = labels.length
  const gw = (W - pad.l - pad.r) / n
  const cx = (i: number) => pad.l + gw * i + gw / 2
  const bw = Math.max(3, Math.min(16, (gw * 0.7) / series.length))
  const ticks = 4
  return (
    <div ref={ref} style={{ position: 'relative', width: '100%' }} onMouseLeave={() => setHi(null)}>
      <svg width={W} height={H} style={{ display: 'block' }}
        onMouseMove={(e) => {
          const r = e.currentTarget.getBoundingClientRect()
          const i = Math.floor((e.clientX - r.left - pad.l) / gw)
          setHi(i >= 0 && i < n ? i : null)
        }}>
        {Array.from({ length: ticks + 1 }).map((_, i) => {
          const v = min + ((max - min) * (ticks - i)) / ticks
          return (
            <g key={i}>
              <line x1={pad.l} x2={W - pad.r} y1={Y(v)} y2={Y(v)} stroke="var(--line-soft)" strokeDasharray="2 4" />
              <text x={pad.l - 6} y={Y(v) + 3.5} textAnchor="end" {...axis}>{short(v)}</text>
            </g>
          )
        })}
        {zeroLine && min < 0 && <line x1={pad.l} x2={W - pad.r} y1={Y(0)} y2={Y(0)} stroke="var(--line)" />}
        {hi !== null && <rect x={pad.l + gw * hi} y={pad.t} width={gw} height={H - pad.t - pad.b} fill="var(--bg-3)" opacity={0.5} />}
        {labels.map((l, i) => <text key={i} x={cx(i)} y={H - 6} textAnchor="middle" {...axis}>{l}</text>)}
        {kind === 'bar' && series.map((s, j) => s.data.map((v, i) => {
          const x = cx(i) - (series.length * bw) / 2 + j * bw
          const y0 = Y(0), y1 = Y(v)
          return <rect key={`${j}-${i}`} x={x} y={Math.min(y0, y1)} width={bw - 1.5} height={Math.max(0, Math.abs(y0 - y1))} rx={2} fill={s.color} opacity={hi === null || hi === i ? 1 : 0.55} />
        }))}
        {kind === 'line' && series.map((s, j) => {
          const d = s.data.map((v, i) => `${i ? 'L' : 'M'}${cx(i).toFixed(1)} ${Y(v).toFixed(1)}`).join(' ')
          return (
            <g key={j}>
              {j === 0 && <path d={`${d} L${cx(n - 1)} ${Y(0)} L${cx(0)} ${Y(0)} Z`} fill={s.color} opacity={0.12} />}
              <path d={d} fill="none" stroke={s.color} strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round" />
              {hi !== null && <circle cx={cx(hi)} cy={Y(s.data[hi])} r={4} fill="var(--bg-1)" stroke={s.color} strokeWidth={2} />}
            </g>
          )
        })}
      </svg>
      {hi !== null && <Tip x={cx(hi)} w={W} row={{ label: labels[hi], values: series.map((s) => ({ name: s.name, value: s.data[hi], color: s.color })) }} />}
    </div>
  )
}

/** Horizontal ranking bars — label, bar, value; share of the top value. */
function HBars({ rows, color, format = moneyK, sub }: { rows: { name: string; value: number; color?: string; sub?: string }[]; color?: string; format?: (n: number) => string; sub?: boolean }) {
  const max = Math.max(1, ...rows.map((r) => r.value))
  if (!rows.length) return <Empty />
  return (
    <div className="col" style={{ gap: 11 }}>
      {rows.map((r, i) => (
        <div key={r.name + i}>
          <div className="row between" style={{ fontSize: 12.5, marginBottom: 4, gap: 8 }}>
            <span className="t-1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.name}{sub && r.sub ? <span className="t-3"> · {r.sub}</span> : null}</span>
            <span className="mono t-2" style={{ flexShrink: 0 }}>{format(r.value)}</span>
          </div>
          <div style={{ height: 8, background: 'var(--bg-3)', borderRadius: 6, overflow: 'hidden' }}>
            <div style={{ height: '100%', width: pct(r.value, max) + '%', background: r.color ?? color ?? CAT[i % CAT.length], borderRadius: 6 }} />
          </div>
        </div>
      ))}
    </div>
  )
}

function Empty({ text = 'No data yet' }: { text?: string }) {
  return <div className="t-3" style={{ padding: '28px 0', textAlign: 'center', fontSize: 12.5 }}>{text}</div>
}

/* ---------- screen ---------- */

export default function DashScreen({ go: _go, user: _user }: { go: Go; user?: AuthUser | null }) {
  const [d, setD] = useState<DashboardSummary | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.dashboard().then((r) => { setD(r); setLoading(false) }).catch(() => setLoading(false))
  }, [])

  if (loading) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading dashboard…</div>
  if (!d) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>Failed to load dashboard.</div>

  const now = new Date()
  const today = `${now.toLocaleDateString('en-GB', { weekday: 'long' })}, ${String(now.getDate()).padStart(2, '0')}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getFullYear()).slice(-2)}`

  const t = d.trend ?? []
  const labels = t.map((m) => m.label)
  const net = t.map((m) => m.income - m.expense)
  const par = pct(d.arrearsAmount, d.outstandingLoanBalance)           // portfolio at risk
  const parTone = par < 5 ? C.green : par < 10 ? C.amber : C.red
  const eff = d.collectionEfficiency
  const effTone = eff === null ? C.grey : eff >= 90 ? C.green : eff >= 75 ? C.amber : C.red
  const liquid = d.bankBalances + d.cashInHand
  const equity = d.totalAssets - d.totalLiabilities
  const lastM = t[t.length - 1], prevM = t[t.length - 2]
  const delta = (cur?: number, prev?: number) => {
    if (cur === undefined || !prev) return null
    const p = ((cur - prev) / Math.abs(prev)) * 100
    return <span style={{ color: p >= 0 ? C.green : C.red }}>{p >= 0 ? '▲' : '▼'} {Math.abs(p).toFixed(0)}% vs last month</span>
  }
  const typeRows = d.portfolioByType ?? []
  const typeTop = typeRows.slice(0, 6)
  const typeRest = typeRows.slice(6).reduce((a, r) => a + r.outstanding, 0)
  const donutData = [...typeTop.map((r, i) => ({ label: r.name, value: r.outstanding, color: CAT[i] })), ...(typeRest > 0 ? [{ label: 'Other', value: typeRest, color: C.grey }] : [])].filter((x) => x.value > 0)
  const statusColor: Record<string, string> = { Active: C.green, Pending: C.amber, Approved: C.blue, Closed: C.grey, Rejected: C.red, WrittenOff: C.red, Restructured: C.violet, Cancelled: C.grey }
  const statuses = d.loanStatusCounts ?? []
  const statusTotal = statuses.reduce((a, s) => a + s.count, 0)
  const agingColors = [C.amber, C.amber, C.red, C.red]

  return (
    <div>
      <PageHead crumbs="Overview" title="Dashboard" sub={today} />

      {/* headline KPIs */}
      <Grid cols="repeat(auto-fit, minmax(210px, 1fr))">
        <Kpi icon="coins" label="Outstanding Loan Balance" value={moneyK(d.outstandingLoanBalance)} tone="blue" series={t.map((m) => m.disbursed)} note={`${moneyK(d.totalLoanPortfolio)} principal`} />
        <Kpi icon="chart" label="Collected This Month" value={moneyK(lastM?.collected ?? 0)} tone="green" series={t.map((m) => m.collected)} note={delta(lastM?.collected, prevM?.collected) ?? `Today ${moneyK(d.todaysCollections)}`} />
        <Kpi icon="pie" label="Net Profit (Month)" value={moneyK(d.netProfit)} tone={d.netProfit >= 0 ? 'green' : 'red'} series={net} note={`Income ${moneyK(d.monthIncome)}`} />
        <Kpi icon="alert" label="Arrears" value={moneyK(d.arrearsAmount)} tone={d.arrearsAmount > 0 ? 'red' : 'green'} note={`${d.overdueLoanCount} loans · ${d.defaultLoans} in default (90+)`} />
        <Kpi icon="wallet" label="Liquidity (Bank + Cash)" value={moneyK(liquid)} tone="blue" note={`Cash ${moneyK(d.cashInHand)}`} />
        <Kpi icon="users" label="Active Customers" value={String(d.activeCustomers)} tone="blue" note={`${d.activeLoanOfficers} loan officers`} />
      </Grid>

      {/* health gauges */}
      <Grid cols="repeat(auto-fit, minmax(240px, 1fr))">
        <Panel title="Portfolio at Risk" sub="Arrears ÷ outstanding balance">
          <div className="col center"><Gauge pct={Math.round(Math.min(100, par) * 10) / 10} color={parTone} label={par < 5 ? 'Healthy' : par < 10 ? 'Watch' : 'High risk'} /></div>
        </Panel>
        <Panel title="Collection Efficiency" sub={`Paid vs due this month (${moneyK(d.dueThisMonth)} due)`}>
          <div className="col center">{eff === null ? <Empty text="No installments due" /> : <Gauge pct={eff} color={effTone} label={eff >= 90 ? 'On track' : eff >= 75 ? 'Behind' : 'Poor'} />}</div>
        </Panel>
        <Panel title="Balance Sheet" sub="Assets vs liabilities">
          <HBars format={moneyK} rows={[
            { name: 'Total assets', value: d.totalAssets, color: C.blue },
            { name: 'Total liabilities', value: d.totalLiabilities, color: C.amber },
            { name: 'Deposits (FD)', value: d.totalDeposits, color: C.violet },
          ]} />
          <div className="row between" style={{ marginTop: 14, paddingTop: 12, borderTop: '1px solid var(--line-soft)', fontSize: 12.5 }}>
            <span className="t-2">Net worth</span>
            <span className="mono" style={{ color: equity >= 0 ? C.green : C.red, fontWeight: 600 }}>{moneyK(equity)}</span>
          </div>
        </Panel>
      </Grid>

      {/* trends */}
      <Grid cols="repeat(auto-fit, minmax(380px, 1fr))">
        <Panel title="Disbursements vs Collections" sub="Last 12 months" right={<Legend items={[{ label: 'Disbursed', color: C.blue }, { label: 'Collected', color: C.green }]} />}>
          <TimeChart kind="bar" labels={labels} series={[{ name: 'Disbursed', data: t.map((m) => m.disbursed), color: C.blue }, { name: 'Collected', data: t.map((m) => m.collected), color: C.green }]} />
        </Panel>
        <Panel title="Income vs Expenses" sub="From the general ledger, last 12 months" right={<Legend items={[{ label: 'Income', color: C.green }, { label: 'Expenses', color: C.amber }, { label: 'Net', color: C.blue }]} />}>
          <TimeChart kind="line" labels={labels} zeroLine series={[{ name: 'Income', data: t.map((m) => m.income), color: C.green }, { name: 'Expenses', data: t.map((m) => m.expense), color: C.amber }, { name: 'Net', data: net, color: C.blue }]} />
        </Panel>
      </Grid>

      {/* portfolio mix */}
      <Grid cols="repeat(auto-fit, minmax(300px, 1fr))">
        <Panel title="Portfolio by Loan Type" sub="Outstanding balance, active loans">
          {donutData.length ? <Donut data={donutData} size={160} thickness={22} center={String(typeRows.reduce((a, r) => a + r.count, 0))} /> : <Empty />}
        </Panel>
        <Panel title="Portfolio by Branch" sub="Outstanding balance">
          <HBars rows={(d.portfolioByBranch ?? []).map((r) => ({ name: r.name, value: r.outstanding, sub: `${r.count} loans` }))} sub color={C.blue} />
        </Panel>
        <Panel title="Arrears Aging" sub="Overdue amount by days past due">
          {(d.arrearsAging ?? []).every((b) => b.count === 0) ? <Empty text="No overdue loans" /> : (
            <HBars sub rows={d.arrearsAging.map((b, i) => ({ name: b.bucket + ' days', value: b.amount, color: agingColors[i], sub: `${b.count} loans` }))} />
          )}
        </Panel>
      </Grid>

      {/* loans pipeline + expenses + funding */}
      <Grid cols="repeat(auto-fit, minmax(300px, 1fr))">
        <Panel title="Loan Book Status" sub={`${statusTotal} loans on record`}>
          {statusTotal ? (
            <>
              <div style={{ display: 'flex', height: 14, borderRadius: 7, overflow: 'hidden', background: 'var(--bg-3)', gap: 2 }}>
                {statuses.map((s) => <div key={s.status} title={`${s.status}: ${s.count}`} style={{ width: pct(s.count, statusTotal) + '%', background: statusColor[s.status] ?? C.grey }} />)}
              </div>
              <div className="col" style={{ gap: 7, marginTop: 14 }}>
                {statuses.map((s) => (
                  <div key={s.status} className="row between" style={{ fontSize: 12.5 }}>
                    <span className="row gap-2 t-1" style={{ alignItems: 'center' }}><span style={{ width: 9, height: 9, borderRadius: 2, background: statusColor[s.status] ?? C.grey }} />{s.status}</span>
                    <span className="mono t-2">{s.count} · {pct(s.count, statusTotal).toFixed(0)}%</span>
                  </div>
                ))}
              </div>
            </>
          ) : <Empty />}
        </Panel>
        <Panel title="Expenses by Category" sub="This month, approved & paid">
          <HBars rows={(d.expenseByCategory ?? []).map((r) => ({ name: r.name, value: r.amount }))} color={C.amber} />
        </Panel>
        <Panel title="Operations Snapshot" sub="Items needing attention">
          <div className="col" style={{ gap: 12 }}>
            {[
              { icon: 'doc', label: 'Loan applications pending', value: String(d.pending.loanApplications), tone: d.pending.loanApplications > 0 ? C.amber : C.green },
              { icon: 'layers', label: 'Active pawnings', value: `${d.pending.pawningActive} · ${moneyK(d.pending.pawningValue)}`, tone: C.blue },
              { icon: 'building', label: 'Fixed deposits maturing (30d)', value: String(d.pending.fdMaturing30), tone: d.pending.fdMaturing30 > 0 ? C.amber : C.green },
              { icon: 'shield', label: 'Loans in default (90+ days)', value: String(d.defaultLoans), tone: d.defaultLoans > 0 ? C.red : C.green },
              { icon: 'wallet', label: 'Bank balances', value: moneyK(d.bankBalances), tone: C.blue },
            ].map((r) => (
              <div key={r.label} className="row between" style={{ fontSize: 12.5, alignItems: 'center' }}>
                <span className="row gap-2 t-1" style={{ alignItems: 'center' }}><span style={{ color: r.tone }}><Icon n={r.icon} s={16} /></span>{r.label}</span>
                <span className="mono" style={{ fontWeight: 600 }}>{r.value}</span>
              </div>
            ))}
          </div>
        </Panel>
      </Grid>

      {/* watchlist */}
      <Panel title="Watchlist — Most Overdue Loans" sub="Longest days past due first" style={{ marginBottom: 16 }}>
        {(d.topOverdue ?? []).length ? (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
              <thead>
                <tr className="t-2" style={{ textAlign: 'left' }}>
                  {['Loan', 'Customer', 'Branch', 'Days overdue', 'Amount due'].map((h, i) => (
                    <th key={h} style={{ padding: '6px 8px', fontWeight: 500, borderBottom: '1px solid var(--line)', textAlign: i > 2 ? 'right' : 'left' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {d.topOverdue.map((r, i) => (
                  <tr key={i}>
                    <td className="mono" style={{ padding: '8px', borderBottom: '1px solid var(--line-soft)' }}>{r.code ?? '—'}</td>
                    <td style={{ padding: '8px', borderBottom: '1px solid var(--line-soft)' }}>{r.customer}</td>
                    <td className="t-2" style={{ padding: '8px', borderBottom: '1px solid var(--line-soft)' }}>{r.branch ?? '—'}</td>
                    <td className="mono" style={{ padding: '8px', borderBottom: '1px solid var(--line-soft)', textAlign: 'right', color: r.daysOverdue >= 90 ? C.red : r.daysOverdue >= 30 ? C.amber : undefined }}>{r.daysOverdue}</td>
                    <td className="mono" style={{ padding: '8px', borderBottom: '1px solid var(--line-soft)', textAlign: 'right' }}>{moneyK(r.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : <Empty text="No overdue loans 🎉" />}
      </Panel>
    </div>
  )
}
