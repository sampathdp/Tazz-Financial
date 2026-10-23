/* Tazz Investment & Credit — Loan Disbursement (Finance queue: approved loans waiting to be paid out) */
import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Card, PageHead, Btn, Badge, Input, Table, Td, Pager, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { loansApi, type Loan, type AuthUser } from '../api'
import { fmtDate } from '../data'
import { DisburseForm } from './loan/LoanDetail'
import { useNotify } from '../components/Notify'
import type { Go } from './types'

const ROUTE_BASE = '/loan/disbursement'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export function LoanDisbursementScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
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
  const [loan, setLoan] = useState<Loan | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  const back = () => navigate(ROUTE_BASE)

  const loadLoan = async (id: number) => {
    const l = await loansApi.getForDisbursement(id)
    // Once disbursed, the loan is out of this queue — return to it.
    if (l.status !== 'Approved' && !l.disbursementIncomplete) { navigate(ROUTE_BASE, { replace: true }); return }
    setLoan(l)
  }

  useEffect(() => {
    if (!params.id) { setLoan(null); return }
    setDetailLoading(true)
    loadLoan(Number(params.id))
      .catch(() => { notify.error('Loan not found'); navigate(ROUTE_BASE, { replace: true }) })
      .finally(() => setDetailLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id])

  const debounceRef = useRef<ReturnType<typeof setTimeout>>()
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current) }
  }, [search])

  useEffect(() => { setPage(1) }, [debouncedSearch])

  useEffect(() => {
    if (params.id) return
    setLoading(true)
    loansApi.awaitingDisbursement({ search: debouncedSearch || undefined, page })
      .then((r) => { setRows(r.data); setLastPage(r.lastPage); setTotal(r.total) })
      .catch(() => { setRows([]); setLastPage(1); setTotal(0) })
      .finally(() => setLoading(false))
  }, [params.id, debouncedSearch, page])

  if (params.id) {
    return (
      <div>
        <PageHead crumbs="Loan Management / Loan Disbursement" title={detailLoading || !loan ? 'Loading…' : `Loan ${loan.code}`} icon="upload"
          sub={loan ? `${loan.customer?.fullName} — ${loan.loanDetail?.loanType?.name}` : undefined}
          actions={<Btn variant="solid" onClick={back}>← Back to Loan Disbursement</Btn>} />
        {detailLoading || !loan ? <LoadingBlock />
          : <DisburseForm loan={loan} me={me} notify={notify}
              onChanged={() => loadLoan(loan.id).catch(() => navigate(ROUTE_BASE, { replace: true }))} />}
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Loan Disbursement" icon="upload" sub={`${total} awaiting disbursement`} />
      <Card pad={0}>
        <div className="row gap-2" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
          <div style={{ position: 'relative', maxWidth: 340, flex: 1 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--tx-2)' }}><Icon n="search" s={15} /></span>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search code, customer…" style={{ paddingLeft: 32 }} />
          </div>
        </div>
        {loading ? <LoadingBlock /> : <>
          <Table cols={[{ label: 'Loan' }, { label: 'Approved' }, { label: 'Branch' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Principal' }, { label: 'Status', align: 'center' }]}
            rows={rows}
            empty="No loans waiting for disbursement."
            onRow={(l) => navigate(`${ROUTE_BASE}/${l.id}`)}
            render={(l) => <>
              <Td c="var(--tx-0)" mono style={{ fontWeight: 600 }}>{l.code}</Td>
              <Td mono>{l.approvedAt ? fmtDate(l.approvedAt) : '—'}</Td>
              <Td>{l.branch?.name || '—'}</Td>
              <Td>
                <div style={{ fontWeight: 600 }}>{l.customer?.fullName}</div>
                <div className="t-2" style={{ fontSize: 11.5 }}>{l.customer?.code}</div>
              </Td>
              <Td>{l.loanDetail?.loanType?.name}</Td>
              <Td mono>{money(l.principalAmount)}</Td>
              <Td align="center">
                {l.pendingDisbursementRequest
                  ? <Badge tone="blue" dot>Requested</Badge>
                  : l.status === 'Active' ? <Badge tone="red" dot>Incomplete</Badge> : <Badge tone="amber" dot>Ready</Badge>}
              </Td>
            </>} />
          <Pager page={page} lastPage={lastPage} total={total} onChange={setPage} />
        </>}
      </Card>
    </div>
  )
}
