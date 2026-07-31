/* Funding Management — Reports: one screen, tab-switched over the report
   types (same "one generic screen, several bindings" idea as Masters.tsx). */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Table, Td } from '../components/ui'
import {
  fundingReportsApi, customersApi,
  type FundingReportType, type FixedDeposit, type FdDeposit, type FdInterestPayment, type FdRenewal, type Customer, type AuthUser,
} from '../api'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')

const TABS: { id: FundingReportType; label: string }[] = [
  { id: 'active', label: 'Active FDs' },
  { id: 'maturing-soon', label: 'Maturing Soon' },
  { id: 'matured', label: 'Matured' },
  { id: 'monthly-interest', label: 'Monthly Interest' },
  { id: 'interest-paid', label: 'Interest Paid' },
  { id: 'deposit-history', label: 'Deposit History' },
  { id: 'customer-funding-history', label: 'Customer Funding' },
  { id: 'renewal-history', label: 'Renewal History' },
  { id: 'capital-enhancements', label: 'Capital Enhancements' },
]

function fdCols() {
  return [{ label: 'FD No' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Principal', align: 'right' as const }, { label: 'Maturity Date' }, { label: 'Status', align: 'center' as const }]
}
function FdRows({ rows }: { rows: FixedDeposit[] }) {
  return <Table cols={fdCols()} rows={rows} empty="No records"
    render={(fd: FixedDeposit) => <>
      <Td mono>{fd.fdNumber}</Td>
      <Td>{fd.customer?.fullName || '—'}</Td>
      <Td>{fd.fundingProduct?.name || '—'}</Td>
      <Td align="right" mono>{money(fd.principalAmount)}</Td>
      <Td>{dateOnly(fd.maturityDate)}</Td>
      <Td align="center"><Badge tone={fd.status === 'Active' ? 'green' : 'neutral'} dot>{fd.status}</Badge></Td>
    </>} />
}

function InterestPaymentRows({ rows }: { rows: FdInterestPayment[] }) {
  return <Table cols={[{ label: 'FD No' }, { label: 'Customer' }, { label: 'Due Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: 'Paid At' }]}
    rows={rows as any[]} empty="No records"
    render={(p: any) => <>
      <Td mono>{p.fixedDeposit?.fdNumber || '—'}</Td>
      <Td>{p.fixedDeposit?.customer?.fullName || '—'}</Td>
      <Td>{dateOnly(p.dueDate)}</Td>
      <Td align="right" mono>{money(p.amount)}</Td>
      <Td align="center"><Badge tone={p.status === 'Paid' ? 'green' : 'amber'} dot>{p.status}</Badge></Td>
      <Td>{dateOnly(p.paidAt)}</Td>
    </>} />
}

function DepositRows({ rows }: { rows: FdDeposit[] }) {
  return <Table cols={[{ label: 'Deposit No' }, { label: 'Customer' }, { label: 'Date' }, { label: 'Method' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: 'FD No' }]}
    rows={rows} empty="No records"
    render={(d: FdDeposit) => <>
      <Td mono>{d.depositNumber}</Td>
      <Td>{d.customer?.fullName || '—'}</Td>
      <Td>{dateOnly(d.depositDate)}</Td>
      <Td>{d.paymentMethod}</Td>
      <Td align="right" mono>{money(d.amount)}</Td>
      <Td align="center"><Badge tone={d.status === 'Available' ? 'amber' : 'green'} dot>{d.status}</Badge></Td>
      <Td mono>{d.fixedDeposit?.fdNumber || '—'}</Td>
    </>} />
}

function RenewalRows({ rows }: { rows: FdRenewal[] }) {
  return <Table cols={[{ label: 'Old FD' }, { label: 'New FD' }, { label: 'Customer' }, { label: 'Old Principal', align: 'right' }, { label: 'Additional', align: 'right' }, { label: 'New Principal', align: 'right' }, { label: 'Renewed At' }]}
    rows={rows} empty="No records"
    render={(r: FdRenewal) => <>
      <Td mono>{r.oldFixedDeposit?.fdNumber || '—'}</Td>
      <Td mono>{r.newFixedDeposit?.fdNumber || '—'}</Td>
      <Td>{r.oldFixedDeposit?.customer?.fullName || '—'}</Td>
      <Td align="right" mono>{money(r.oldPrincipal)}</Td>
      <Td align="right" mono>{Number(r.additionalDepositAmount) > 0 ? money(r.additionalDepositAmount) : '—'}</Td>
      <Td align="right" mono>{money(r.newPrincipal)}</Td>
      <Td>{dateOnly(r.renewedAt)}</Td>
    </>} />
}

export function FundingReportsScreen({ go: _go, user: _me }: { go: Go; user?: AuthUser | null }) {
  const [tab, setTab] = useState<FundingReportType>('active')
  const [loading, setLoading] = useState(true)
  const [data, setData] = useState<any>(null)
  const [err, setErr] = useState('')

  // per-tab filter state
  const [days, setDays] = useState(30)
  const [monthlyStatus, setMonthlyStatus] = useState<'' | 'Pending' | 'Paid'>('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [customers, setCustomers] = useState<Customer[]>([])
  const [customerLabel, setCustomerLabel] = useState('')
  const [customerId, setCustomerId] = useState<number | undefined>()

  useEffect(() => { customersApi.list().then(setCustomers).catch(() => setCustomers([])) }, [])

  const load = () => {
    setErr('')
    if (tab === 'customer-funding-history' && !customerId) { setData(null); setLoading(false); return }
    setLoading(true)
    const params: Record<string, any> =
      tab === 'maturing-soon' ? { days } :
      tab === 'monthly-interest' ? { status: monthlyStatus || undefined } :
      tab === 'interest-paid' ? { from: fromDate || undefined, to: toDate || undefined } :
      tab === 'deposit-history' ? { customerId: customerId || undefined } :
      tab === 'customer-funding-history' ? { customerId } : {}
    fundingReportsApi.get(tab, params).then(setData).catch((ex) => { setErr(getErrorText(ex, 'Failed to load report')); setData(null) }).finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [tab, days, monthlyStatus, fromDate, toDate, customerId]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Funding Reports" icon="chart" />
      <Card pad={0} style={{ marginBottom: 16 }}>
        <div className="row gap-1 wrap" style={{ padding: 14 }}>
          {TABS.map((t) => <Btn key={t.id} size="sm" variant="ghost" active={tab === t.id} onClick={() => setTab(t.id)}>{t.label}</Btn>)}
        </div>
      </Card>

      {tab === 'maturing-soon' && (
        <Card style={{ marginBottom: 16 }}><Field label="Within Days"><Input type="number" value={days} onChange={(e) => setDays(Number(e.target.value) || 30)} style={{ maxWidth: 160 }} /></Field></Card>
      )}
      {tab === 'monthly-interest' && (
        <Card style={{ marginBottom: 16 }}><Field label="Status"><Select value={monthlyStatus} onChange={(e) => setMonthlyStatus(e.target.value as any)} style={{ maxWidth: 200 }}>
          <option value="">All</option><option value="Pending">Pending</option><option value="Paid">Paid</option>
        </Select></Field></Card>
      )}
      {tab === 'interest-paid' && (
        <Card style={{ marginBottom: 16 }}>
          <div className="row gap-3 wrap">
            <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
            <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
          </div>
        </Card>
      )}
      {(tab === 'deposit-history' || tab === 'customer-funding-history') && (
        <Card style={{ marginBottom: 16 }}>
          <Field label={tab === 'customer-funding-history' ? 'Customer (required)' : 'Customer (optional)'}>
            <SearchSelect value={customerLabel} onChange={(name, opt: Customer | undefined) => { setCustomerId(opt?.id); setCustomerLabel(name) }}
              options={customers} getLabel={(c: Customer) => `${c.code} — ${c.fullName}`} getSub={(c: Customer) => c.nicNo || ''} placeholder="Search customer…" />
          </Field>
        </Card>
      )}

      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
         err ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{err}</div> :
         tab === 'customer-funding-history' && !customerId ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Pick a customer to view their funding history.</div> :
         (tab === 'active' || tab === 'maturing-soon' || tab === 'matured') ? <FdRows rows={data || []} /> :
         (tab === 'monthly-interest' || tab === 'interest-paid') ? <InterestPaymentRows rows={data || []} /> :
         tab === 'deposit-history' ? <DepositRows rows={data || []} /> :
         tab === 'customer-funding-history' ? (
           <div className="col gap-4" style={{ padding: 16 }}>
             <div>
               <div className="eyebrow" style={{ marginBottom: 8 }}>Deposits</div>
               <DepositRows rows={data?.deposits || []} />
             </div>
             <div>
               <div className="eyebrow" style={{ marginBottom: 8 }}>Fixed Deposits</div>
               <FdRows rows={data?.fixedDeposits || []} />
             </div>
           </div>
         ) :
         (tab === 'renewal-history' || tab === 'capital-enhancements') ? <RenewalRows rows={data || []} /> : null}
      </Card>
    </div>
  )
}

export default FundingReportsScreen
