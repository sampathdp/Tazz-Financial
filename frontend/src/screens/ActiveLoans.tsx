/* Tazz Investment & Credit — Active Loans (disbursement → repayment schedule → payments) */
import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Card, PageHead, Btn, Badge, Input, Table, Td, Pager, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { loansApi, type Loan, type LoanStatus, type AuthUser } from '../api'
import { LoanDetail } from './loan/LoanDetail'
import { useNotify } from '../components/Notify'
import type { Go } from './types'

const ROUTE_BASE = '/loan/active'

const LIVE_STATUSES: LoanStatus[] = ['Approved', 'Active', 'Closed', 'WrittenOff', 'Restructured']
const statusTone: Record<LoanStatus, 'green' | 'red' | 'amber' | 'blue' | 'neutral'> = {
  Pending: 'amber', Approved: 'blue', Rejected: 'red', Cancelled: 'red', Active: 'green', Closed: 'neutral', WrittenOff: 'red', Restructured: 'amber',
}
const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export function ActiveLoansScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const navigate = useNavigate()
  const params = useParams<{ id?: string }>()
  const [rows, setRows] = useState<Loan[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<LoanStatus | 'All'>('All')
  const [counts, setCounts] = useState<Record<string, number>>({ All: 0 })

  const [mode, setMode] = useState<'list' | 'detail'>('list')
  const [loan, setLoan] = useState<Loan | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  // Which loan (if any) is open lives in the URL — see the effect below —
  // so a hard refresh on a detail URL reopens the same loan instead of
  // bouncing back to the list.
  const openRow = (row: Loan) => navigate(`${ROUTE_BASE}/${row.id}`)
  const back = () => navigate(ROUTE_BASE)
  const reloadLoan = async () => { if (loan) setLoan(await loansApi.get(loan.id)) }

  useEffect(() => {
    if (!params.id) { setMode('list'); setLoan(null); loansApi.statusCounts().then(setCounts).catch(() => {}); return }
    setMode('detail'); setDetailLoading(true)
    loansApi.get(Number(params.id))
      .then(setLoan)
      .catch(() => { notify.error('Loan not found'); navigate(ROUTE_BASE, { replace: true }) })
      .finally(() => setDetailLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id])

  // Search text is debounced so a keystroke doesn't fire a request per
  // character (see LoanCalculator.tsx for the same debounce-ref shape);
  // status/page changes refresh immediately, no typing involved.
  const debounceRef = useRef<ReturnType<typeof setTimeout>>()
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current) }
  }, [search])

  // A status/search change always jumps back to page 1 — staying on page 5
  // of a now-different, shorter result set would just show an empty page.
  useEffect(() => { setPage(1) }, [statusFilter, debouncedSearch])

  useEffect(() => {
    if (params.id) return
    setLoading(true)
    loansApi.browse({ status: statusFilter === 'All' ? undefined : statusFilter, search: debouncedSearch || undefined, page })
      .then((r) => { setRows(r.data); setLastPage(r.lastPage); setTotal(r.total) })
      .catch(() => { setRows([]); setLastPage(1); setTotal(0) })
      .finally(() => setLoading(false))
  }, [params.id, statusFilter, debouncedSearch, page])

  if (mode === 'detail') {
    return (
      <div>
        <div className="no-print">
          <PageHead crumbs="Loan Management / Active Loans" title={detailLoading || !loan ? 'Loading…' : `Loan ${loan.code}`} icon="coins"
            sub={loan ? `${loan.customer?.fullName} — ${loan.loanDetail?.loanType?.name}` : undefined}
            actions={<Btn variant="solid" onClick={back}>← Back to Active Loans</Btn>} />
        </div>
        {detailLoading || !loan ? <LoadingBlock />
          : <LoanDetail loan={loan} me={me} onChanged={reloadLoan} go={go} context="active" />}
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Active Loans" icon="coins" sub={`${total} loans`} />

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
          {search.trim() && <span className="t-2" style={{ fontSize: 12 }}>{total} match{total === 1 ? '' : 'es'}</span>}
        </div>
        {loading ? <LoadingBlock /> : <>
        <Table cols={[{ label: 'Loan' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Principal' }, { label: 'Outstanding' }, { label: 'Status', align: 'center' }]}
          rows={rows}
          empty={LIVE_STATUSES.includes(statusFilter as LoanStatus) || statusFilter === 'All' ? 'No approved or active loans yet — approve one in Loan Applications first.' : 'No loans in this status'}
          onRow={openRow}
          render={(l) => <>
            <Td c="var(--tx-0)" mono style={{ fontWeight: 600 }}>{l.code}</Td>
            <Td>
              <div style={{ fontWeight: 600 }}>{l.customer?.fullName}</div>
              <div className="t-2" style={{ fontSize: 11.5 }}>{l.customer?.code}</div>
              {!!l.coApplicants?.length && <div className="t-2" style={{ fontSize: 11 }}>+{l.coApplicants.length} co-applicant{l.coApplicants.length > 1 ? 's' : ''}</div>}
            </Td>
            <Td>{l.loanDetail?.loanType?.name}</Td>
            <Td mono>{money(l.principalAmount)}</Td>
            <Td mono>{l.status === 'Approved' ? '—' : money(num(l.totalDueSum) - num(l.paidSum))}</Td>
            <Td align="center"><Badge tone={statusTone[l.status]} dot>{l.status}</Badge></Td>
          </>} />
        <Pager page={page} lastPage={lastPage} total={total} onChange={setPage} />
        </>}
      </Card>
    </div>
  )
}

