/* Loan Inquiry — search a customer once, see every Loan, Speed Draft and Pawning they hold. */
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Card, PageHead, Badge, Input, Table, Td, statusTone, LoadingBlock } from '../components/ui'
import { loanInquiryApi, type LoanInquiryCustomer, type LoanInquiryFacility, type AuthUser } from '../api'
import { canAccessRoute } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import type { Go } from './types'

const money = (v: unknown) => Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const TYPE_LABEL: Record<LoanInquiryFacility['type'], string> = { Loan: 'Loan', SpeedDraft: 'Speed Draft', Pawning: 'Pawning' }
const TYPE_ROUTE: Record<LoanInquiryFacility['type'], string> = { Loan: 'loan/active', SpeedDraft: 'sd/drafts', Pawning: 'pawn/tickets' }

export function LoanInquiryScreen({ user }: { go: Go; user?: AuthUser | null }) {
  const navigate = useNavigate()
  const notify = useNotify()

  // Each facility's own screen is gated by its module permission; navigating
  // there without it hits the router's permission fallback and lands on /dash.
  // Loans can always open inside the customer view (same permission as this
  // screen), so prefer the module screen only when the user may see it.
  const openFacility = (c: LoanInquiryCustomer, f: LoanInquiryFacility) => {
    const route = TYPE_ROUTE[f.type]
    if (canAccessRoute(user, route)) return navigate(`/${route}/${f.id}`)
    if (f.type === 'Loan') return navigate(`/customers/${c.id}/loan/${f.id}`)
    notify.error(`You don't have permission to view ${TYPE_LABEL[f.type]} records`)
  }
  const [search, setSearch] = useState('')
  const [results, setResults] = useState<LoanInquiryCustomer[]>([])
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    const term = search.trim()
    if (term.length < 2) { setResults([]); return }
    setLoading(true)
    const t = setTimeout(() => {
      loanInquiryApi.search(term).then(setResults).catch(() => setResults([])).finally(() => setLoading(false))
    }, 300)
    return () => clearTimeout(t)
  }, [search])

  const term = search.trim()
  return (
    <div>
      <PageHead title="Loan Inquiry" sub="Search a customer (name, NIC, phone, code) or any loan / speed draft / pawn ticket number" icon="search" />
      <Card style={{ marginBottom: 16 }}>
        <Input autoFocus placeholder="Type at least 2 characters…" value={search} onChange={(e) => setSearch(e.target.value)} style={{ width: '100%' }} />
      </Card>
      {loading && <LoadingBlock />}
      {!loading && term.length >= 2 && results.length === 0 && <Card>No customers found.</Card>}
      {results.map((c) => (
        <Card key={c.id} style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10, gap: 12, flexWrap: 'wrap' }}>
            <div>
              <div style={{ fontWeight: 600 }}>{c.fullName} <span style={{ color: 'var(--tx-3)', fontWeight: 400 }}>· {c.code}</span></div>
              <div style={{ fontSize: 12, color: 'var(--tx-3)' }}>{[c.nicNo, c.phone].filter(Boolean).join(' · ')}</div>
            </div>
            <div style={{ fontSize: 12, color: 'var(--tx-3)' }}>{c.facilities.length} facilit{c.facilities.length === 1 ? 'y' : 'ies'}</div>
          </div>
          <Table
            cols={[{ label: 'Type' }, { label: 'Number' }, { label: 'Status' }, { label: 'Amount', align: 'right' }, { label: 'Outstanding', align: 'right' }, { label: 'Disbursed' }, { label: 'Maturity' }] as any}
            rows={c.facilities}
            empty="No loans, speed drafts or pawnings for this customer"
            onRow={(f: LoanInquiryFacility) => openFacility(c, f)}
            render={(f: LoanInquiryFacility) => (
              <>
                <Td><Badge tone="blue">{TYPE_LABEL[f.type]}</Badge></Td>
                <Td mono>{f.code}</Td>
                <Td><Badge tone={statusTone(f.status)}>{f.status}</Badge></Td>
                <Td align="right" mono>{money(f.principalAmount)}</Td>
                <Td align="right" mono>{money(f.outstandingBalance)}</Td>
                <Td>{f.disbursedAt?.slice(0, 10) ?? '–'}</Td>
                <Td>{f.maturityDate?.slice(0, 10) ?? '–'}</Td>
              </>
            )}
          />
        </Card>
      ))}
    </div>
  )
}
