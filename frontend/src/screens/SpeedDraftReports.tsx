/* Speed Draft Reports — eight standalone screens (Portfolio Summary, PAR/NPL Aging, Arrears,
   Disbursement, Collections, Maturity Schedule, Closed Drafts, Draft Detail), each its own nav
   entry. The backend (SpeedDraftReportController) returns one generic payload for every type
   — KPI tiles, an optional bar chart and one or more tables — so a single ReportScreen renders
   them all, and the Excel / PDF exports are built from that same payload. */
import { useEffect, useState, type ReactNode } from 'react'
import { Card, PageHead, Field, Input, Select, Table, Td, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { ExportButtons } from '../components/ExportButtons'
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import { DateRangeSelect } from '../components/DateRangeSelect'
import { BarChart } from '../components/charts'
import { speedDraftReportsApi, speedDraftTypesApi, type SpeedDraftReport, type SpeedDraftReportType, type SpeedDraftType, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportReportToExcel } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)
const money = (v: unknown) => Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const moneyShort = (v: number) => (Math.abs(v) >= 1000 ? (v / 1000).toFixed(1) + 'k' : v.toFixed(0))
const TONE = {
  blue: ['var(--ac-bright)', 'var(--ac-dim)'], green: ['var(--ok)', 'var(--ok-dim)'],
  amber: ['var(--warn)', 'var(--warn-dim)'], red: ['var(--bad)', 'var(--bad-dim)'],
} as const

const fmt = (v: unknown, format?: string | null) => {
  if (v === null || v === undefined || v === '') return '—'
  if (format === 'money') return money(v)
  if (format === 'percent') return `${Number(v).toFixed(2)}%`
  if (format === 'number') return Number(v).toLocaleString()
  return String(v)
}

function Tile({ icon, label, value, tone = 'blue' }: { icon: string; label: string; value: string; tone?: keyof typeof TONE }) {
  const [tc, td] = TONE[tone] || TONE.blue
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

interface Config {
  type: SpeedDraftReportType
  crumb: string
  icon: string
  file: string
  /** Show From/To (with a period preset) and what to default them to. */
  dates?: boolean
  defaultFrom?: () => string
  defaultTo?: () => string
  /** Show the Status filter (Draft Detail only). */
  status?: boolean
}

const addDays = (n: number) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10) }
const monthStart = () => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1).toLocaleDateString('en-CA') }

function ReportScreen({ cfg, user: me }: { cfg: Config; user?: AuthUser | null }) {
  const [data, setData] = useState<SpeedDraftReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)
  const [types, setTypes] = useState<SpeedDraftType[]>([])
  const [typeId, setTypeId] = useState<number | ''>('')
  const [from, setFrom] = useState(cfg.defaultFrom ? cfg.defaultFrom() : monthStart())
  const [to, setTo] = useState(cfg.defaultTo ? cfg.defaultTo() : today())
  const [status, setStatus] = useState('')

  useEffect(() => { speedDraftTypesApi.list().then(setTypes).catch(() => setTypes([])) }, [])

  const params = {
    branchId, speedDraftTypeId: typeId || undefined,
    from: cfg.dates ? from : undefined, to: cfg.dates ? to : undefined, status: status || undefined,
  }
  useEffect(() => {
    setLoading(true); setErr('')
    speedDraftReportsApi.get(cfg.type, params).then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branchId, typeId, from, to, status])

  const exportExcel = () => {
    if (!data) return
    const summary = data.tiles.map((t) => ({ metric: t.label, value: fmt(t.value, t.format) }))
    void exportReportToExcel({
      sheetName: data.title.slice(0, 28), reportTitle: data.title, meta: data.meta,
      sections: [
        ...(summary.length ? [{ title: 'Summary', columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right' as const }], rows: summary }] : []),
        ...data.sections.map((s) => ({ title: s.title, columns: s.columns, rows: s.rows, totalRow: s.totalRow, emptyText: s.emptyText })),
      ],
    }, `${cfg.file}-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await speedDraftReportsApi.pdf(cfg.type, params), `${cfg.file}-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Speed Drafts / Reports" title={data?.title || 'Speed Draft Report'} icon={cfg.icon}
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          {cfg.dates && <>
            <DateRangeSelect from={from} to={to} onChange={(f, t) => { setFrom(f); setTo(t) }} style={{ minWidth: 190 }} />
            <Field label="From"><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
            <Field label="To"><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
          </>}
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
          <Field label="Speed Draft Type">
            <Select value={typeId} onChange={(e) => setTypeId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">All Types</option>
              {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </Select>
          </Field>
          {cfg.status && (
            <Field label="Status">
              <Select value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">All Statuses</option>
                {['Pending', 'Approved', 'Active', 'Closed', 'Rejected'].map((s) => <option key={s} value={s}>{s}</option>)}
              </Select>
            </Field>
          )}
        </div>
      </Card>

      {loading ? <LoadingBlock /> : err ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{err}</div> : data && (
        <>
          <div className="t-2" style={{ fontSize: 12, marginBottom: 12 }}>{data.meta}</div>
          {data.tiles.length > 0 && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 16, marginBottom: 16 }}>
              {data.tiles.map((t) => <Tile key={t.label} icon={t.icon || 'chart'} label={t.label} value={fmt(t.value, t.format)} tone={(t.tone || 'blue') as keyof typeof TONE} />)}
            </div>
          )}
          {data.chart && data.chart.data.length > 0 && (
            <Card style={{ marginBottom: 16 }}>
              <div className="eyebrow" style={{ marginBottom: 14 }}>{data.chart.title}</div>
              <BarChart height={220} format={moneyShort} keys={['value']} colors={[data.chart.color === 'bad' ? 'var(--bad)' : 'var(--ac-bright)']} data={data.chart.data} />
            </Card>
          )}
          {data.sections.map((s, i) => <SectionTable key={i} section={s} />)}
        </>
      )}
    </div>
  )
}

function SectionTable({ section: s }: { section: SpeedDraftReport['sections'][number] }) {
  const cell = (c: SpeedDraftReport['sections'][number]['columns'][number], v: unknown): ReactNode => fmt(v, c.format)
  return (
    <Card pad={0} style={{ marginBottom: 16 }}>
      {s.title && <div className="eyebrow" style={{ padding: '14px 16px 0' }}>{s.title}</div>}
      <Table cols={s.columns.map((c) => ({ label: c.label, align: c.align }))} rows={s.rows} empty={s.emptyText || 'No data'}
        render={(r: Record<string, any>) => <>
          {s.columns.map((c) => <Td key={c.key} mono={c.format === 'money' || c.format === 'number' || c.format === 'percent'} align={c.align}>{cell(c, r[c.key])}</Td>)}
        </>}
        footer={s.totalRow && s.rows.length > 0 ? (
          <tr style={{ fontWeight: 700, background: 'var(--bg-1)' }}>
            {s.columns.map((c) => <Td key={c.key} mono={c.format === 'money' || c.format === 'number'} align={c.align}>{s.totalRow![c.key] === undefined ? '' : cell(c, s.totalRow![c.key])}</Td>)}
          </tr>
        ) : undefined} />
    </Card>
  )
}

type P = { go: Go; user?: AuthUser | null }

export const SpeedDraftPortfolioSummaryScreen = ({ user }: P) => <ReportScreen user={user} cfg={{ type: 'portfolio-summary', crumb: 'Portfolio Summary', icon: 'coins', file: 'speed-draft-portfolio-summary' }} />
export const SpeedDraftParAgingScreen = ({ user }: P) => <ReportScreen user={user} cfg={{ type: 'par-aging', crumb: 'PAR / NPL Aging', icon: 'target', file: 'speed-draft-par-aging' }} />
export const SpeedDraftArrearsScreen = ({ user }: P) => <ReportScreen user={user} cfg={{ type: 'arrears', crumb: 'Arrears', icon: 'alert', file: 'speed-draft-arrears' }} />
export const SpeedDraftDisbursementReportScreen = ({ user }: P) => <ReportScreen user={user} cfg={{ type: 'disbursement', crumb: 'Disbursement', icon: 'upload', file: 'speed-draft-disbursement', dates: true }} />
export const SpeedDraftClosedScreen = ({ user }: P) => <ReportScreen user={user} cfg={{ type: 'closed', crumb: 'Closed Drafts', icon: 'check', file: 'speed-draft-closed', dates: true }} />
export const SpeedDraftCollectionsScreen = ({ user }: P) => <ReportScreen user={user} cfg={{ type: 'collections', crumb: 'Collections', icon: 'wallet', file: 'speed-draft-collections', dates: true }} />
export const SpeedDraftMaturityScreen = ({ user }: P) => <ReportScreen user={user} cfg={{ type: 'maturity', crumb: 'Maturity Schedule', icon: 'clock', file: 'speed-draft-maturity', dates: true, defaultFrom: today, defaultTo: () => addDays(30) }} />
export const SpeedDraftDetailReportScreen = ({ user }: P) => <ReportScreen user={user} cfg={{ type: 'draft-detail', crumb: 'Draft Detail', icon: 'list', file: 'speed-draft-detail', status: true }} />
