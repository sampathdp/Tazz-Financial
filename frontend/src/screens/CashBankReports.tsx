/* Cash & Bank reports: three standalone screens (Position, Cashbook
   Activity, Bank Activity), each its own nav entry and route — no tabs.
   Same shape as LoanReports.tsx. */
import { useEffect, useState, type ReactNode } from 'react'
import { Card, PageHead, Field, Input, Table, Td } from '../components/ui'
import { Icon } from '../components/Icon'
import { Donut } from '../components/charts'
import { cashBankReportsApi, type AuthUser } from '../api'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

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
function Loading() { return <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> }
function ErrBox({ msg }: { msg: string }) { return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{msg}</div> }

/* ---------------------------- Position ---------------------------- */

export function CashBankPositionScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    cashBankReportsApi.get('position').then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [])

  const split = data ? [
    { label: 'Cash', value: data.totalCash, color: PALETTE[0] },
    { label: 'Bank', value: data.totalBank, color: PALETTE[1] },
  ].filter((d) => d.value > 0) : []

  return (
    <div>
      <PageHead crumbs="Cash & Bank / Reports" title="Cash & Bank Position" icon="building" />
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

export function CashbookActivityScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    setLoading(true); setErr('')
    cashBankReportsApi.get('cashbook-activity', { from: fromDate || undefined, to: toDate || undefined })
      .then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate])

  const byCategory = data ? Object.entries(data.byCategory || {}).map(([label, v]: [string, any], i) => ({ label, value: (v.in || 0) + (v.out || 0), color: PALETTE[i % PALETTE.length] })).filter((d) => d.value > 0) : []

  return (
    <div>
      <PageHead crumbs="Cash & Bank / Reports" title="Cashbook Activity" icon="receipt" />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
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

export function BankActivityScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')

  useEffect(() => {
    setLoading(true); setErr('')
    cashBankReportsApi.get('bank-activity', { from: fromDate || undefined, to: toDate || undefined })
      .then(setData).catch((ex) => setErr(getErrorText(ex, 'Failed to load report'))).finally(() => setLoading(false))
  }, [fromDate, toDate])

  const byType = data ? Object.entries(data.byType || {}).map(([label, value], i) => ({ label, value: Number(value), color: PALETTE[i % PALETTE.length] })).filter((d) => d.value > 0) : []

  return (
    <div>
      <PageHead crumbs="Cash & Bank / Reports" title="Bank Activity" icon="swap" />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap">
          <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
          <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
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
