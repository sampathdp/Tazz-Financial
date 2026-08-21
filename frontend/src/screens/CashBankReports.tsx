/* Cash & Bank reports: three standalone screens (Position, Cashbook
   Activity, Bank Activity), each its own nav entry and route — no tabs.
   Same shape as LoanReports.tsx. */
import { useEffect, useState, type ReactNode } from 'react'
import { Card, PageHead, Field, Input, Table, Td , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { ExportButtons } from '../components/ExportButtons'
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import { DateRangeSelect } from '../components/DateRangeSelect'
import { Donut } from '../components/charts'
import { cashBankReportsApi, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportReportToExcel } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
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

/* ---------------------------- Position ---------------------------- */

export function CashBankPositionScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true)
    cashBankReportsApi.get('position', { branchId }).then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [branchId])

  const split = data ? [
    { label: 'Cash', value: data.totalCash, color: PALETTE[0] },
    { label: 'Bank', value: data.totalBank, color: PALETTE[1] },
  ].filter((d) => d.value > 0) : []

  const exportExcel = () => {
    if (!data) return
    void exportReportToExcel({
      sheetName: 'Cash & Bank Position', reportTitle: 'Cash & Bank Position',
      sections: [
        {
          title: 'Cash Accounts',
          columns: [{ label: 'Name', key: 'name' }, { label: 'Branch', key: 'branch' }, { label: 'Balance', key: 'balance', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' }],
          rows: (data.cashAccounts || []).map((a: any) => ({ name: a.name, branch: a.branch?.name || '—', balance: Number(a.currentBalance || 0), status: a.status })),
          totalRow: { name: 'Total Cash', balance: Number(data.totalCash || 0) }, emptyText: 'No cash accounts',
        },
        {
          title: 'Bank Accounts',
          columns: [{ label: 'Name', key: 'name' }, { label: 'Bank', key: 'bank' }, { label: 'Balance', key: 'balance', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' }],
          rows: (data.bankAccounts || []).map((a: any) => ({ name: a.name, bank: a.bank?.name || '—', balance: Number(a.currentBalance || 0), status: a.status })),
          totalRow: { name: 'Total Bank', balance: Number(data.totalBank || 0) }, emptyText: 'No bank accounts',
        },
        {
          columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right', format: 'money' }],
          rows: [{ metric: 'Grand Total Liquidity', value: Number(data.grandTotal || 0) }],
        },
      ],
    }, `cash-bank-position-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await cashBankReportsApi.pdf('position', { branchId }), `cash-bank-position-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Cash & Bank / Reports" title="Cash & Bank Position" icon="building"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}><div className="row gap-3 wrap"><BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} /></div></Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : data && (
        <>
          <TileGrid>
            <Tile icon="wallet" label="Total Cash" value={money(data.totalCash)} />
            <Tile icon="building" label="Total Bank" value={money(data.totalBank)} />
            <Tile icon="coins" label="Grand Total" value={money(data.grandTotal)} tone="green" />
          </TileGrid>
          {split.length > 0 && (
            <ChartCard title="Cash vs Bank Split">
              <Donut data={split} center={money(data.grandTotal)} />
            </ChartCard>
          )}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Card pad={0}>
              <div className="eyebrow" style={{ padding: '14px 20px 0' }}>Cash Accounts</div>
              <Table cols={[{ label: 'Name' }, { label: 'Branch' }, { label: 'Balance', align: 'right' as const }, { label: 'Status', align: 'center' as const }]}
                rows={data.cashAccounts || []} empty="No cash accounts"
                render={(a: any) => <><Td>{a.name}</Td><Td>{a.branch?.name || '—'}</Td><Td align="right" mono>{money(a.currentBalance)}</Td><Td align="center">{a.status}</Td></>} />
            </Card>
            <Card pad={0}>
              <div className="eyebrow" style={{ padding: '14px 20px 0' }}>Bank Accounts</div>
              <Table cols={[{ label: 'Name' }, { label: 'Bank' }, { label: 'Balance', align: 'right' as const }, { label: 'Status', align: 'center' as const }]}
                rows={data.bankAccounts || []} empty="No bank accounts"
                render={(a: any) => <><Td>{a.name}</Td><Td>{a.bank?.name || '—'}</Td><Td align="right" mono>{money(a.currentBalance)}</Td><Td align="center">{a.status}</Td></>} />
            </Card>
          </div>
        </>
      )}
    </div>
  )
}

/* ---------------------------- Cashbook Activity ---------------------------- */

export function CashbookActivityScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true); setErr('')
    cashBankReportsApi.get('cashbook-activity', { from: fromDate || undefined, to: toDate || undefined, branchId })
      .then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, branchId])

  const byCategory = data ? Object.entries(data.byCategory || {}).map(([label, v]: [string, any], i) => ({ label, value: (v.in || 0) + (v.out || 0), color: PALETTE[i % PALETTE.length] })).filter((d) => d.value > 0) : []

  const exportExcel = () => {
    if (!data) return
    void exportReportToExcel({
      sheetName: 'Cashbook Activity', reportTitle: 'Cashbook Activity', meta: `Period: ${data.from} to ${data.to}`,
      sections: [
        { title: 'Summary', columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right', format: 'money' }], rows: [{ metric: 'Total In', value: Number(data.totalIn || 0) }, { metric: 'Total Out', value: Number(data.totalOut || 0) }] },
        {
          title: 'By Category', columns: [{ label: 'Category', key: 'category' }, { label: 'In', key: 'in', align: 'right', format: 'money' }, { label: 'Out', key: 'out', align: 'right', format: 'money' }],
          rows: Object.entries(data.byCategory || {}).map(([k, v]: [string, any]) => ({ category: k, in: Number(v.in || 0), out: Number(v.out || 0) })), emptyText: 'No entries in this range',
        },
        {
          title: 'Entries',
          columns: [
            { label: 'Date', key: 'date' }, { label: 'Cash Account', key: 'account' }, { label: 'Branch', key: 'branch' }, { label: 'Direction', key: 'direction', align: 'center' },
            { label: 'Category', key: 'category' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'By', key: 'by' },
          ],
          rows: (data.entries || []).map((e: any) => ({
            date: typeof e.entryDate === 'string' ? e.entryDate.slice(0, 10) : '—', account: e.cashAccount?.name || '—', branch: e.branch?.name || '—',
            direction: e.direction, category: e.category, amount: Number(e.amount || 0), by: e.creator?.name || '—',
          })),
          emptyText: 'No entries in this range',
        },
      ],
    }, `cashbook-activity-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await cashBankReportsApi.pdf('cashbook-activity', { from: fromDate || undefined, to: toDate || undefined, branchId }), `cashbook-activity-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Cash & Bank / Reports" title="Cashbook Activity" icon="receipt"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : data && (
        <>
          <TileGrid>
            <Tile icon="arrowUp" label="Total In" value={money(data.totalIn)} tone="green" />
            <Tile icon="arrowDown" label="Total Out" value={money(data.totalOut)} tone="amber" />
          </TileGrid>
          {byCategory.length > 0 && (
            <ChartCard title="Activity by Category">
              <Donut data={byCategory} />
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[
              { label: 'Date' }, { label: 'Cash Account' }, { label: 'Branch' }, { label: 'Category' },
              { label: 'Direction', align: 'center' as const }, { label: 'Amount', align: 'right' as const }, { label: 'By' },
            ]} rows={data.entries || []} empty="No entries in this range"
              render={(e: any) => <>
                <Td>{typeof e.entryDate === 'string' ? e.entryDate.slice(0, 10) : '—'}</Td>
                <Td>{e.cashAccount?.name || '—'}</Td>
                <Td>{e.branch?.name || '—'}</Td>
                <Td>{e.category}</Td>
                <Td align="center">{e.direction}</Td>
                <Td align="right" mono>{money(e.amount)}</Td>
                <Td>{e.creator?.name || '—'}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}

/* ---------------------------- Bank Activity ---------------------------- */

export function BankActivityScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  useEffect(() => {
    setLoading(true); setErr('')
    cashBankReportsApi.get('bank-activity', { from: fromDate || undefined, to: toDate || undefined, branchId })
      .then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate, branchId])

  const byType = data ? Object.entries(data.byType || {}).map(([label, value], i) => ({ label, value: Number(value), color: PALETTE[i % PALETTE.length] })).filter((d) => d.value > 0) : []

  const exportExcel = () => {
    if (!data) return
    void exportReportToExcel({
      sheetName: 'Bank Activity', reportTitle: 'Bank Activity', meta: `Period: ${data.from} to ${data.to}`,
      sections: [
        { title: 'Summary', columns: [{ label: 'Metric', key: 'metric' }, { label: 'Value', key: 'value', align: 'right', format: 'money' }], rows: [{ metric: 'Total Credits', value: Number(data.totalCredits || 0) }, { metric: 'Total Debits', value: Number(data.totalDebits || 0) }] },
        {
          title: 'By Transaction Type', columns: [{ label: 'Type', key: 'type' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' }],
          rows: Object.entries(data.byType || {}).map(([k, v]) => ({ type: k, amount: Number(v as number) })), emptyText: 'No transactions in this range',
        },
        {
          title: 'Transactions',
          columns: [
            { label: 'Date', key: 'date' }, { label: 'Bank Account', key: 'account' }, { label: 'Type', key: 'type' },
            { label: 'Reference', key: 'reference' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' }, { label: 'By', key: 'by' },
          ],
          rows: (data.transactions || []).map((t: any) => ({
            date: typeof t.txnDate === 'string' ? t.txnDate.slice(0, 10) : '—', account: t.bankAccount?.name || '—', type: t.transactionType,
            reference: t.referenceNo || '—', amount: Number(t.amount || 0), status: t.status, by: t.creator?.name || '—',
          })),
          emptyText: 'No transactions in this range',
        },
      ],
    }, `bank-activity-${today()}.xlsx`)
  }
  const exportPdf = async () => downloadBlob(await cashBankReportsApi.pdf('bank-activity', { from: fromDate || undefined, to: toDate || undefined, branchId }), `bank-activity-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Cash & Bank / Reports" title="Bank Activity" icon="swap"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          <BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} />
        </div>
      </Card>
      {loading ? <Loading /> : err ? <ErrBox msg={err} /> : data && (
        <>
          <TileGrid>
            <Tile icon="arrowUp" label="Total Credits" value={money(data.totalCredits)} tone="green" />
            <Tile icon="arrowDown" label="Total Debits" value={money(data.totalDebits)} tone="amber" />
          </TileGrid>
          {byType.length > 0 && (
            <ChartCard title="Activity by Type">
              <Donut data={byType} />
            </ChartCard>
          )}
          <Card pad={0}>
            <Table cols={[
              { label: 'Date' }, { label: 'Bank Account' }, { label: 'Type' },
              { label: 'Reference' }, { label: 'Amount', align: 'right' as const }, { label: 'Status', align: 'center' as const },
            ]} rows={data.transactions || []} empty="No transactions in this range"
              render={(t: any) => <>
                <Td>{typeof t.txnDate === 'string' ? t.txnDate.slice(0, 10) : '—'}</Td>
                <Td>{t.bankAccount?.name || '—'}</Td>
                <Td>{t.transactionType}</Td>
                <Td mono>{t.referenceNo || '—'}</Td>
                <Td align="right" mono>{money(t.amount)}</Td>
                <Td align="center">{t.status}</Td>
              </>} />
          </Card>
        </>
      )}
    </div>
  )
}
