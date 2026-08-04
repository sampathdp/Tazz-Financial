/* Tazz Investment & Credit — Active Loans (disbursement → repayment schedule → payments) */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Input, Table, Td } from '../components/ui'
import { Icon } from '../components/Icon'
import { loansApi, type Loan, type LoanStatus, type AuthUser } from '../api'
import { LoanDetail } from './loan/LoanDetail'
import type { Go } from './types'

const LIVE_STATUSES: LoanStatus[] = ['Approved', 'Active', 'Closed', 'WrittenOff', 'Restructured']
const statusTone: Record<LoanStatus, 'green' | 'red' | 'amber' | 'blue' | 'neutral'> = {
  Pending: 'amber', Approved: 'blue', Rejected: 'red', Active: 'green', Closed: 'neutral', WrittenOff: 'red', Restructured: 'amber',
}
const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export function ActiveLoansScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<LoanStatus | 'All'>('All')

  const [mode, setMode] = useState<'list' | 'detail'>('list')
  const [loan, setLoan] = useState<Loan | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  const refresh = () => {
    setLoading(true)
    loansApi.list().then((all) => setRows(all.filter((l) => LIVE_STATUSES.includes(l.status)))).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])

  const openRow = async (row: Loan) => {
    setMode('detail'); setDetailLoading(true)
    try { setLoan(await loansApi.get(row.id)) } catch { setLoan(row) } finally { setDetailLoading(false) }
  }
  const back = () => { setMode('list'); setLoan(null); refresh() }
  const reloadLoan = async () => { if (loan) setLoan(await loansApi.get(loan.id)) }

  const q = search.trim().toLowerCase()
  const filtered = rows.filter((l) => {
    if (statusFilter !== 'All' && l.status !== statusFilter) return false
    if (!q) return true
    return [l.code, l.customer?.fullName, l.customer?.code, l.loanDetail?.loanType?.name].some((v) => (v || '').toLowerCase().includes(q))
  })
  const counts = useMemo(() => {
    const c: Record<string, number> = { All: rows.length }
    for (const l of rows) c[l.status] = (c[l.status] || 0) + 1
    return c
  }, [rows])

  if (mode === 'detail') {
    return (
      <div>
        <PageHead crumbs="Loan Management / Active Loans" title={detailLoading || !loan ? 'Loading…' : `Loan ${loan.code}`} icon="coins"
          sub={loan ? `${loan.customer?.fullName} — ${loan.loanDetail?.loanType?.name}` : undefined}
          actions={<Btn variant="solid" onClick={back}>← Back to Active Loans</Btn>} />
        {detailLoading || !loan ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div>
          : <LoanDetail loan={loan} me={me} onChanged={reloadLoan} go={go} context="active" />}
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Active Loans" icon="coins" sub={`${rows.length} loans`} />

      <div className="row gap-2 wrap" style={{ marginBottom: 14 }}>
        {(['All', ...LIVE_STATUSES] as const).map((s) => (
          <Btn key={s} size="sm" variant={statusFilter === s ? 'primary' : 'solid'} onClick={() => setStatusFilter(s)}>
            {s} {counts[s] ? <span style={{ opacity: 0.7 }}>({counts[s]})</span> : ''}
          </Btn>
        ))}
      </div>

      <Card pad={0}>
        <div className="row gap-2" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
          <div style={{ position: 'relative', maxWidth: 340, flex: 1 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--tx-2)' }}><Icon n="search" s={15} /></span>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search code, customer, product…" style={{ paddingLeft: 32 }} />
          </div>
          {q && <span className="t-2" style={{ fontSize: 12 }}>{filtered.length} of {rows.length}</span>}
        </div>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Loan' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Principal' }, { label: 'Outstanding' }, { label: 'Status', align: 'center' }]}
          rows={filtered}
          empty={LIVE_STATUSES.includes(statusFilter as LoanStatus) || statusFilter === 'All' ? 'No approved or active loans yet — approve one in Loan Applications first.' : 'No loans in this status'}
          onRow={openRow}
          render={(l) => <>
            <Td c="var(--tx-0)" mono style={{ fontWeight: 600 }}>{l.code}</Td>
            <Td><div style={{ fontWeight: 600 }}>{l.customer?.fullName}</div><div className="t-2" style={{ fontSize: 11.5 }}>{l.customer?.code}</div></Td>
            <Td>{l.loanDetail?.loanType?.name}</Td>
            <Td mono>{money(l.principalAmount)}</Td>
            <Td mono>{l.status === 'Approved' ? '—' : money(num(l.totalDueSum) - num(l.paidSum))}</Td>
            <Td align="center"><Badge tone={statusTone[l.status]} dot>{l.status}</Badge></Td>
          </>} />}
      </Card>
    </div>
  )
}

