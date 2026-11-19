/* Tazz Investment & Credit — Cheque Report. Monitoring view over every loan /
   speed-draft cheque (post-dated cheques + Cheque-method payments): how many
   passed (Realized), how many were returned (Bounced), how many are still
   pending, with amounts, return rate and a per-customer returned-cheque count
   so repeat offenders stand out.

   Reuses the cross-loan GET /post-dated-cheques listing that Cheque Clearance
   uses (admin-only on the backend), so the route is kept off
   lib/permissions.ts's ROUTE_MODULE and falls back to admin-only, same as
   Cheque Clearance. Status / branch / search are filtered client-side over one
   fetch per period, so the summary tiles always reflect the same data set. */
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { isAdminRole } from '../lib/permissions'
import { Card, PageHead, Btn, Field, Input, Select, Table, Td, Badge, DateInput, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { ExportButtons } from '../components/ExportButtons'
import { DateRangeSelect } from '../components/DateRangeSelect'
import { loanPostDatedChequesApi, type AuthUser, type LoanPostDatedCheque, type ChequeStatus } from '../api'
import { getErrorText } from '../lib/apiError'
import { exportReportToExcel } from '../lib/export'
import type { Go } from './types'

const money = (v: unknown) => Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

/** Report buckets. 'Bounced' and the legacy 'Returned' status both mean the bank sent it back. */
type Outcome = 'Passed' | 'Returned' | 'Pending'
const outcomeOf = (s: ChequeStatus): Outcome =>
  s === 'Realized' ? 'Passed' : s === 'Bounced' || s === 'Returned' ? 'Returned' : 'Pending'
const outcomeTone: Record<Outcome, 'green' | 'red' | 'amber'> = { Passed: 'green', Returned: 'red', Pending: 'amber' }

function Tile({ icon, label, count, amount, tone }: { icon: string; label: string; count: ReactNode; amount?: string; tone: 'blue' | 'green' | 'amber' | 'red' }) {
  const tc = { blue: 'var(--ac-bright)', green: 'var(--ok)', amber: 'var(--warn)', red: 'var(--bad)' }[tone]
  const td = { blue: 'var(--ac-dim)', green: 'var(--ok-dim)', amber: 'var(--warn-dim)', red: 'var(--bad-dim)' }[tone]
  return (
    <Card pad={18}>
      <div style={{ width: 38, height: 38, borderRadius: 10, background: td, display: 'grid', placeItems: 'center', color: tc, marginBottom: 14 }}>
        <Icon n={icon} s={20} />
      </div>
      <div className="num" style={{ fontSize: 24, fontWeight: 700, fontFamily: 'Saira', letterSpacing: '-0.01em' }}>{count}</div>
      <div className="t-2" style={{ fontSize: 12, marginTop: 4 }}>{label}</div>
      {amount !== undefined && <div className="num" style={{ fontSize: 13, marginTop: 6, color: 'var(--tx-1)' }}>{amount}</div>}
    </Card>
  )
}

export function ChequeReportScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<LoanPostDatedCheque[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [outcome, setOutcome] = useState<Outcome | ''>('')
  const [branch, setBranch] = useState('')
  const [search, setSearch] = useState('')
  const [minReturns, setMinReturns] = useState(1)

  const admin = isAdminRole(me?.role)

  useEffect(() => {
    if (!admin) return
    setLoading(true); setErr('')
    loanPostDatedChequesApi.listAll({ fromDate, toDate })
      .then(setRows).catch((ex) => { setRows([]); setErr(getErrorText(ex, 'Failed to load cheques')) }).finally(() => setLoading(false))
  }, [fromDate, toDate, admin])

  const branches = useMemo(() => {
    const names = new Set<string>()
    rows.forEach((c) => { if (c.loan?.branch?.name) names.add(c.loan.branch.name) })
    return [...names].sort()
  }, [rows])

  // Branch + search narrow the data set the tiles summarise; the outcome filter only narrows the detail table.
  const scoped = useMemo(() => {
    const q = search.trim().toLowerCase()
    return rows.filter((c) => {
      if (branch && c.loan?.branch?.name !== branch) return false
      if (!q) return true
      return [c.chequeNo, c.loan?.code, c.loan?.customer?.fullName, c.bankName].some((v) => (v || '').toLowerCase().includes(q))
    })
  }, [rows, branch, search])

  const summary = useMemo(() => {
    const s = { Passed: { n: 0, amt: 0 }, Returned: { n: 0, amt: 0 }, Pending: { n: 0, amt: 0 } }
    scoped.forEach((c) => { const o = s[outcomeOf(c.status)]; o.n += 1; o.amt += Number(c.amount || 0) })
    const decided = s.Passed.n + s.Returned.n
    return { ...s, total: scoped.length, totalAmt: s.Passed.amt + s.Returned.amt + s.Pending.amt, returnRate: decided ? (s.Returned.n / decided) * 100 : 0 }
  }, [scoped])

  const detail = useMemo(() => (outcome ? scoped.filter((c) => outcomeOf(c.status) === outcome) : scoped), [scoped, outcome])

  /** Returned cheques grouped by customer — who keeps bouncing cheques. */
  const byCustomer = useMemo(() => {
    const m = new Map<string, { customer: string; loans: Set<string>; total: number; returned: number; returnedAmt: number; passed: number }>()
    scoped.forEach((c) => {
      const key = String(c.loan?.customer?.id ?? c.loan?.customer?.fullName ?? '—')
      const e = m.get(key) ?? { customer: c.loan?.customer?.fullName || '—', loans: new Set<string>(), total: 0, returned: 0, returnedAmt: 0, passed: 0 }
      e.total += 1
      if (c.loan?.code) e.loans.add(c.loan.code)
      const o = outcomeOf(c.status)
      if (o === 'Returned') { e.returned += 1; e.returnedAmt += Number(c.amount || 0) }
      if (o === 'Passed') e.passed += 1
      m.set(key, e)
    })
    return [...m.values()].filter((e) => e.returned >= minReturns).sort((a, b) => b.returned - a.returned || b.returnedAmt - a.returnedAmt)
  }, [scoped, minReturns])

  const periodText = fromDate || toDate ? `Period: ${fromDate || 'beginning'} to ${toDate || 'today'}` : 'Period: all dates'
  const filterText = [periodText, branch && `Branch: ${branch}`, outcome && `Status: ${outcome}`, search && `Search: "${search}"`].filter(Boolean).join(' · ')

  const exportExcel = () => {
    void exportReportToExcel({
      sheetName: 'Cheque Report', reportTitle: 'Cheque Report', meta: filterText,
      sections: [
        {
          title: 'Summary',
          columns: [{ label: 'Status', key: 'k' }, { label: 'Count', key: 'n', align: 'right', format: 'number' }, { label: 'Amount', key: 'amt', align: 'right', format: 'money' }],
          rows: (['Passed', 'Returned', 'Pending'] as Outcome[]).map((k) => ({ k, n: summary[k].n, amt: summary[k].amt })),
          totalRow: { k: `Total (return rate ${summary.returnRate.toFixed(1)}%)`, n: summary.total, amt: summary.totalAmt },
        },
        {
          title: `Returned Cheques by Customer (${minReturns}+ returns)`,
          columns: [{ label: 'Customer', key: 'customer' }, { label: 'Loans', key: 'loans' }, { label: 'Total Cheques', key: 'total', align: 'right', format: 'number' }, { label: 'Passed', key: 'passed', align: 'right', format: 'number' }, { label: 'Returned', key: 'returned', align: 'right', format: 'number' }, { label: 'Returned Amount', key: 'returnedAmt', align: 'right', format: 'money' }],
          rows: byCustomer.map((e) => ({ customer: e.customer, loans: [...e.loans].join(', '), total: e.total, passed: e.passed, returned: e.returned, returnedAmt: e.returnedAmt })),
          emptyText: 'No returned cheques',
        },
        {
          title: outcome ? `${outcome} Cheques` : 'All Cheques',
          columns: [{ label: 'Cheque No', key: 'no' }, { label: 'Loan', key: 'loan' }, { label: 'Customer', key: 'customer' }, { label: 'Branch', key: 'branch' }, { label: 'Bank', key: 'bank' }, { label: 'Cheque Date', key: 'date' }, { label: 'Cleared On', key: 'cleared' }, { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' }],
          rows: detail.map((c) => ({ no: c.chequeNo, loan: c.loan?.code || '—', customer: c.loan?.customer?.fullName || '—', branch: c.loan?.branch?.name || '—', bank: c.bankName || '—', date: dateOnly(c.chequeDate), cleared: c.status === 'Realized' ? dateOnly(c.realizedAt) : '—', amount: Number(c.amount || 0), status: outcomeOf(c.status) })),
          totalRow: { no: `${detail.length} cheques`, amount: detail.reduce((t, c) => t + Number(c.amount || 0), 0) },
          emptyText: 'No cheques',
        },
      ],
    }, `cheque-report-${todayIso()}.xlsx`)
  }

  if (!admin) {
    return <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>Admin privileges required.</div>
  }

  const hasFilters = fromDate || toDate || outcome || branch || search

  return (
    <div>
      <PageHead crumbs="Reports / Cash & Bank" title="Cheque Report" icon="cheque"
        sub="Passed, returned and pending cheques across all loans — with return counts per customer."
        actions={!loading && !err && <ExportButtons onExcel={exportExcel} />} />

      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-3 wrap" style={{ alignItems: 'flex-end' }}>
          <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} />
          <Field label="From"><DateInput value={fromDate} onChange={setFromDate} /></Field>
          <Field label="To"><DateInput value={toDate} onChange={setToDate} /></Field>
          <Field label="Status"><Select value={outcome} onChange={(e) => setOutcome(e.target.value as Outcome | '')} style={{ minWidth: 150 }}>
            <option value="">All</option>
            <option value="Passed">Passed</option>
            <option value="Returned">Returned</option>
            <option value="Pending">Pending</option>
          </Select></Field>
          <Field label="Branch"><Select value={branch} onChange={(e) => setBranch(e.target.value)} style={{ minWidth: 170 }}>
            <option value="">All Branches</option>
            {branches.map((b) => <option key={b} value={b}>{b}</option>)}
          </Select></Field>
          <Field label="Search"><Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cheque no, loan, customer, bank" style={{ minWidth: 220 }} /></Field>
          {hasFilters && (
            <Btn variant="ghost" icon="x" onClick={() => { setFromDate(''); setToDate(''); setOutcome(''); setBranch(''); setSearch('') }}>Clear</Btn>
          )}
        </div>
        <div className="t-2" style={{ fontSize: 11.5, marginTop: 10 }}>
          Date filter uses the clearing date for passed cheques and the cheque date for all others.
        </div>
      </Card>

      {loading ? <LoadingBlock /> : err ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)' }}>{err}</div> : (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16, marginBottom: 16 }}>
            <Tile icon="cheque" label="Total Cheques" count={summary.total} amount={money(summary.totalAmt)} tone="blue" />
            <Tile icon="check" label="Passed" count={summary.Passed.n} amount={money(summary.Passed.amt)} tone="green" />
            <Tile icon="x" label="Returned" count={summary.Returned.n} amount={money(summary.Returned.amt)} tone="red" />
            <Tile icon="clock" label="Pending" count={summary.Pending.n} amount={money(summary.Pending.amt)} tone="amber" />
            <Tile icon="chart" label="Return Rate (of passed + returned)" count={`${summary.returnRate.toFixed(1)}%`} tone={summary.returnRate > 10 ? 'red' : 'blue'} />
          </div>

          <Card pad={0} style={{ marginBottom: 16 }}>
            <div className="row between wrap gap-2" style={{ padding: '14px 20px 0', alignItems: 'center' }}>
              <div className="eyebrow">Returned Cheques by Customer</div>
              <div className="row gap-2" style={{ alignItems: 'center' }}>
                <span className="t-2" style={{ fontSize: 12 }}>Min. returns</span>
                <Select value={minReturns} onChange={(e) => setMinReturns(Number(e.target.value))} style={{ width: 80 }}>
                  {[1, 2, 3, 5].map((n) => <option key={n} value={n}>{n}+</option>)}
                </Select>
              </div>
            </div>
            <Table cols={[{ label: 'Customer' }, { label: 'Loans' }, { label: 'Total Cheques', align: 'right' }, { label: 'Passed', align: 'right' }, { label: 'Returned', align: 'right' }, { label: 'Returned Amount', align: 'right' }]}
              rows={byCustomer} empty="No returned cheques"
              render={(e) => <>
                <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{e.customer}</Td>
                <Td>{[...e.loans].join(', ') || '—'}</Td>
                <Td align="right" mono>{e.total}</Td>
                <Td align="right" mono>{e.passed}</Td>
                <Td align="right" mono><Badge tone={e.returned >= 3 ? 'red' : 'amber'}>{e.returned}</Badge></Td>
                <Td align="right" mono>{money(e.returnedAmt)}</Td>
              </>} />
          </Card>

          <Card pad={0}>
            <div className="eyebrow" style={{ padding: '14px 20px 0' }}>{outcome ? `${outcome} Cheques` : 'All Cheques'} — {detail.length}</div>
            <Table cols={[{ label: 'Cheque No' }, { label: 'Loan' }, { label: 'Customer' }, { label: 'Branch' }, { label: 'Bank' }, { label: 'Cheque Date' }, { label: 'Cleared On' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }]}
              rows={detail} empty="No cheques found"
              render={(c) => {
                const o = outcomeOf(c.status)
                return <>
                  <Td mono>{c.chequeNo}</Td>
                  <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{c.loan?.code || '—'}</Td>
                  <Td>{c.loan?.customer?.fullName || '—'}</Td>
                  <Td>{c.loan?.branch?.name || '—'}</Td>
                  <Td>{c.bankName || '—'}</Td>
                  <Td>{dateOnly(c.chequeDate)}</Td>
                  <Td>{c.status === 'Realized' ? dateOnly(c.realizedAt) : '—'}</Td>
                  <Td align="right" mono>{money(c.amount)}</Td>
                  <Td align="center"><Badge tone={outcomeTone[o]} dot>{o}</Badge></Td>
                </>
              }} />
          </Card>
        </>
      )}
    </div>
  )
}
