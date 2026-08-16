/* Tazz Investment & Credit — Overdue Loans (Active loans with a past-due, unpaid installment) */
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Card, PageHead, Btn, Badge, Input, Table, Td, Field } from '../components/ui'
import { Icon } from '../components/Icon'
import { loansApi, type Loan, type OverdueLoan, type AuthUser } from '../api'
import { LoanDetail } from './loan/LoanDetail'
import { useNotify } from '../components/Notify'
import type { Go } from './types'

const ROUTE_BASE = '/loan/overdue'
const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const daysTone = (d: number) => (d >= 30 ? 'red' : d >= 15 ? 'amber' : 'neutral') as 'red' | 'amber' | 'neutral'
const todayIso = () => new Date().toISOString().slice(0, 10)

export function OverdueLoansScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const navigate = useNavigate()
  const params = useParams<{ id?: string }>()
  const [rows, setRows] = useState<OverdueLoan[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState(todayIso())

  const [mode, setMode] = useState<'list' | 'detail'>('list')
  const [loan, setLoan] = useState<Loan | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  const refresh = () => {
    setLoading(true)
    loansApi.overdue({ from: from || undefined, to: to || undefined }).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }

  // Which loan (if any) is open lives in the URL — see the effect below —
  // so a hard refresh on a detail URL reopens the same loan instead of
  // bouncing back to the list.
  const openRow = (row: OverdueLoan) => navigate(`${ROUTE_BASE}/${row.id}`)
  const back = () => navigate(ROUTE_BASE)
  const reloadLoan = async () => { if (loan) setLoan(await loansApi.get(loan.id)) }

  useEffect(() => {
    if (!params.id) { setMode('list'); setLoan(null); refresh(); return }
    setMode('detail'); setDetailLoading(true)
    loansApi.get(Number(params.id))
      .then(setLoan)
      .catch(() => { notify.error('Loan not found'); navigate(ROUTE_BASE, { replace: true }) })
      .finally(() => setDetailLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id, from, to])

  const q = search.trim().toLowerCase()
  const filtered = rows.filter((l) => {
    if (!q) return true
    return [l.code, l.customer?.fullName, l.customer?.code, l.loanDetail?.loanType?.name].some((v) => (v || '').toLowerCase().includes(q))
  })
  const totalOverdueAmount = rows.reduce((a, l) => a + num(l.overdueAmount) + num(l.penaltyDue), 0)

  if (mode === 'detail') {
    return (
      <div>
        <PageHead crumbs="Loan Management / Overdue Loans" title={detailLoading || !loan ? 'Loading…' : `Loan ${loan.code}`} icon="alert"
          sub={loan ? `${loan.customer?.fullName} — ${loan.loanDetail?.loanType?.name}` : undefined}
          actions={<Btn variant="solid" onClick={back}>← Back to Overdue Loans</Btn>} />
        {detailLoading || !loan ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div>
          : <LoanDetail loan={loan} me={me} onChanged={reloadLoan} go={go} context="active" />}
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Overdue Loans" icon="alert"
        sub={`${rows.length} loans with a past-due installment${rows.length ? ` — ${money(totalOverdueAmount)} total overdue` : ''}`} />

      <Card pad={0}>
        <div className="row gap-2" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
          <div style={{ position: 'relative', maxWidth: 340, flex: 1 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--tx-2)' }}><Icon n="search" s={15} /></span>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search code, customer, product…" style={{ paddingLeft: 32 }} />
          </div>
          <Field label="From"><Input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} /></Field>
          <Field label="To (as of)"><Input type="date" value={to} onChange={(e) => setTo(e.target.value || todayIso())} /></Field>
          {(from || to !== todayIso()) &&
            <Btn variant="ghost" onClick={() => { setFrom(''); setTo(todayIso()) }}>Reset</Btn>}
          {q && <span className="t-2" style={{ fontSize: 12 }}>{filtered.length} of {rows.length}</span>}
        </div>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[
          { label: 'Loan' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Days Overdue', align: 'center' },
          { label: 'Installments', align: 'center' }, { label: 'Overdue Amount', align: 'right' }, { label: 'OD / Penalty', align: 'right' },
          { label: 'Outstanding', align: 'right' }, { label: 'Recovery Officer' },
        ]}
          rows={filtered} empty="No overdue loans — every active loan is up to date."
          onRow={openRow}
          render={(l) => <>
            <Td c="var(--tx-0)" mono style={{ fontWeight: 600 }}>{l.code}</Td>
            <Td>
              <div style={{ fontWeight: 600 }}>{l.customer?.fullName}</div>
              <div className="t-2" style={{ fontSize: 11.5 }}>{l.customer?.code}</div>
              {!!l.coApplicants?.length && <div className="t-2" style={{ fontSize: 11 }}>+{l.coApplicants.length} co-applicant{l.coApplicants.length > 1 ? 's' : ''}</div>}
            </Td>
            <Td>{l.loanDetail?.loanType?.name}</Td>
            <Td align="center"><Badge tone={daysTone(l.daysOverdue)} dot>{l.daysOverdue}d</Badge></Td>
            <Td align="center">{l.overdueInstallments.toFixed(2)}</Td>
            <Td mono align="right">{money(l.overdueAmount)}</Td>
            <Td mono align="right">{money(l.penaltyDue)}</Td>
            <Td mono align="right">{money(num(l.totalDueSum) - num(l.paidSum))}</Td>
            <Td>{l.loanDetail?.recoveryOfficer?.name || '—'}</Td>
          </>} />}
      </Card>
    </div>
  )
}
