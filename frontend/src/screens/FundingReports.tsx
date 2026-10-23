/* Funding Management — Reports: one screen, tab-switched over the report
   types (same "one generic screen, several bindings" idea as Masters.tsx). */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Table, Td , LoadingBlock } from '../components/ui'
import { ExportButtons } from '../components/ExportButtons'
import { BranchFilter, useBranchFilter } from '../components/BranchFilter'
import { DateRangeSelect } from '../components/DateRangeSelect'
import {
  fundingReportsApi, customersApi,
  type FundingReportType, type FixedDeposit, type FdDeposit, type FdInterestPayment, type FdRenewal, type Customer, type AuthUser,
} from '../api'
import { getErrorText } from '../lib/apiError'
import { downloadBlob, exportFlatTableToExcel, exportReportToExcel, type ExcelColumn } from '../lib/export'
import type { Go } from './types'

const today = () => new Date().toISOString().slice(0, 10)

const FD_COLS: ExcelColumn[] = [
  { label: 'FD No', key: 'fdNo' }, { label: 'Customer', key: 'customer' }, { label: 'Product', key: 'product' },
  { label: 'Principal', key: 'principal', align: 'right', format: 'money' }, { label: 'Maturity Date', key: 'maturity' }, { label: 'Status', key: 'status', align: 'center' },
]
const fdRows = (rows: FixedDeposit[]) => rows.map((fd) => ({
  fdNo: fd.fdNumber, customer: fd.customer?.fullName || '—', product: fd.fundingProduct?.name || '—',
  principal: Number(fd.principalAmount || 0), maturity: dateOnly(fd.maturityDate), status: fd.status,
}))

const INTEREST_COLS: ExcelColumn[] = [
  { label: 'FD No', key: 'fdNo' }, { label: 'Customer', key: 'customer' }, { label: 'Due Date', key: 'due' },
  { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' }, { label: 'Paid At', key: 'paidAt' },
]
const interestRows = (rows: FdInterestPayment[]) => (rows as any[]).map((p) => ({
  fdNo: p.fixedDeposit?.fdNumber || '—', customer: p.fixedDeposit?.customer?.fullName || '—', due: dateOnly(p.dueDate),
  amount: Number(p.amount || 0), status: p.status, paidAt: dateOnly(p.paidAt),
}))

// "Interest Paid" is the payout register: who got each payment, into which account, and how it left the company.
const INTEREST_PAID_COLS: ExcelColumn[] = [
  { label: 'Voucher No', key: 'voucherNo' }, { label: 'Paid At', key: 'paidAt' }, { label: 'FD No', key: 'fdNo' },
  { label: '#', key: 'installment', align: 'center' }, { label: 'Paid To', key: 'customer' },
  { label: 'Payee Bank', key: 'payeeBank' }, { label: 'Account No', key: 'payeeAccount' },
  { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'Method', key: 'method' },
  { label: 'Paid From', key: 'paidFrom' }, { label: 'Reference', key: 'reference' }, { label: 'Paid By', key: 'paidBy' },
]
const payeeBank = (p: any) => {
  const bd = p.fixedDeposit?.bankDetail
  return bd ? `${bd.bank?.name || ''}${bd.bankBranch ? ` · ${bd.bankBranch}` : ''}`.trim() || '—' : '—'
}
const paidFrom = (p: any) => p.bankAccount?.name || (p.paymentMethod === 'Cash' ? 'Cash' : '—')
const interestPaidRows = (rows: any[]) => rows.map((p) => ({
  voucherNo: p.voucherNo || '—', paidAt: dateOnly(p.paidAt), fdNo: p.fixedDeposit?.fdNumber || '—', installment: p.installmentNo,
  customer: p.fixedDeposit?.customer?.fullName || '—', payeeBank: payeeBank(p), payeeAccount: p.fixedDeposit?.bankDetail?.accountNumber || '—',
  amount: Number(p.paidAmount || 0), method: p.paymentMethod || '—', paidFrom: paidFrom(p), reference: p.referenceNo || '—', paidBy: p.payer?.name || '—',
}))

const DEPOSIT_COLS: ExcelColumn[] = [
  { label: 'Deposit No', key: 'depositNo' }, { label: 'Customer', key: 'customer' }, { label: 'Date', key: 'date' }, { label: 'Method', key: 'method' },
  { label: 'Amount', key: 'amount', align: 'right', format: 'money' }, { label: 'Status', key: 'status', align: 'center' }, { label: 'FD No', key: 'fdNo' },
]
const depositRows = (rows: FdDeposit[]) => rows.map((d) => ({
  depositNo: d.depositNumber, customer: d.customer?.fullName || '—', date: dateOnly(d.depositDate), method: d.paymentMethod,
  amount: Number(d.amount || 0), status: d.status, fdNo: d.fixedDeposit?.fdNumber || '—',
}))

const RENEWAL_COLS: ExcelColumn[] = [
  { label: 'Old FD', key: 'oldFd' }, { label: 'New FD', key: 'newFd' }, { label: 'Customer', key: 'customer' },
  { label: 'Old Principal', key: 'oldPrincipal', align: 'right', format: 'money' }, { label: 'Additional', key: 'additional', align: 'right', format: 'money' },
  { label: 'New Principal', key: 'newPrincipal', align: 'right', format: 'money' }, { label: 'Renewed At', key: 'renewedAt' },
]
const renewalRows = (rows: FdRenewal[]) => rows.map((r) => ({
  oldFd: r.oldFixedDeposit?.fdNumber || '—', newFd: r.newFixedDeposit?.fdNumber || '—', customer: r.oldFixedDeposit?.customer?.fullName || '—',
  oldPrincipal: Number(r.oldPrincipal || 0), additional: Number(r.additionalDepositAmount || 0), newPrincipal: Number(r.newPrincipal || 0), renewedAt: dateOnly(r.renewedAt),
}))

const WITHDRAWAL_COLS: ExcelColumn[] = [
  { label: 'Voucher No', key: 'voucherNo' }, { label: 'FD No', key: 'fdNo' }, { label: 'Customer', key: 'customer' }, { label: 'Type', key: 'type', align: 'center' },
  { label: 'Principal', key: 'principal', align: 'right', format: 'money' }, { label: 'Interest', key: 'interest', align: 'right', format: 'money' },
  { label: 'Clawback', key: 'clawback', align: 'right', format: 'money' }, { label: 'Total Paid', key: 'total', align: 'right', format: 'money' },
  { label: 'Method', key: 'method' }, { label: 'Closed At', key: 'closedAt' },
]
const withdrawalRows = (rows: any[]) => rows.map((w) => ({
  voucherNo: w.voucherNo, fdNo: w.fixedDeposit?.fdNumber || '—', customer: w.fixedDeposit?.customer?.fullName || '—', type: w.isPremature ? 'Premature' : 'Matured',
  principal: Number(w.principalAmount || 0), interest: Number(w.interestAmount || 0), clawback: Number(w.prematureClawback || 0), total: Number(w.totalAmount || 0),
  method: w.paymentMethod, closedAt: dateOnly(w.closedAt),
}))

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')

const TABS: { id: FundingReportType; label: string }[] = [
  { id: 'active', label: 'Active FDs' },
  { id: 'maturing-soon', label: 'Maturing Soon' },
  { id: 'matured', label: 'Matured' },
  { id: 'monthly-interest', label: 'Monthly Interest' },
  { id: 'interest-paid', label: 'Interest Payments' },
  { id: 'deposit-history', label: 'Deposit History' },
  { id: 'customer-funding-history', label: 'Customer Funding' },
  { id: 'renewal-history', label: 'Renewal History' },
  { id: 'capital-enhancements', label: 'Capital Enhancements' },
  { id: 'withdrawals', label: 'Withdrawals' },
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

function InterestPaidRows({ rows }: { rows: any[] }) {
  return <Table cols={[{ label: 'Voucher No' }, { label: 'Paid At' }, { label: 'FD No' }, { label: '#', align: 'center' }, { label: 'Paid To' }, { label: 'Payee Bank' }, { label: 'Account No' },
      { label: 'Amount', align: 'right' }, { label: 'Method' }, { label: 'Paid From' }, { label: 'Reference' }, { label: 'Paid By' }]}
    rows={rows} empty="No records"
    render={(p: any) => <>
      <Td mono>{p.voucherNo || '—'}</Td>
      <Td>{dateOnly(p.paidAt)}</Td>
      <Td mono>{p.fixedDeposit?.fdNumber || '—'}</Td>
      <Td align="center">{p.installmentNo}</Td>
      <Td>
        <div style={{ fontWeight: 600 }}>{p.fixedDeposit?.customer?.fullName || '—'}</div>
        {p.fixedDeposit?.bankDetail?.accountHolder && <div className="t-2" style={{ fontSize: 11.5 }}>{p.fixedDeposit.bankDetail.accountHolder}</div>}
      </Td>
      <Td>{payeeBank(p)}</Td>
      <Td mono>{p.fixedDeposit?.bankDetail?.accountNumber || '—'}</Td>
      <Td align="right" mono>{money(p.paidAmount)}</Td>
      <Td>{p.paymentMethod || '—'}</Td>
      <Td>{paidFrom(p)}</Td>
      <Td>{p.referenceNo || '—'}</Td>
      <Td>{p.payer?.name || '—'}</Td>
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

function WithdrawalRows({ rows }: { rows: any[] }) {
  return <Table cols={[{ label: 'Voucher No' }, { label: 'FD No' }, { label: 'Customer' }, { label: 'Type', align: 'center' }, { label: 'Principal', align: 'right' }, { label: 'Interest', align: 'right' }, { label: 'Clawback', align: 'right' }, { label: 'Total Paid', align: 'right' }, { label: 'Method' }, { label: 'Closed At' }]}
    rows={rows} empty="No records"
    render={(w: any) => <>
      <Td mono>{w.voucherNo}</Td>
      <Td mono>{w.fixedDeposit?.fdNumber || '—'}</Td>
      <Td>{w.fixedDeposit?.customer?.fullName || '—'}</Td>
      <Td align="center"><Badge tone={w.isPremature ? 'amber' : 'green'} dot>{w.isPremature ? 'Premature' : 'Matured'}</Badge></Td>
      <Td align="right" mono>{money(w.principalAmount)}</Td>
      <Td align="right" mono>{money(w.interestAmount)}</Td>
      <Td align="right" mono>{Number(w.prematureClawback) > 0 ? money(w.prematureClawback) : '—'}</Td>
      <Td align="right" mono>{money(w.totalAmount)}</Td>
      <Td>{w.paymentMethod}</Td>
      <Td>{dateOnly(w.closedAt)}</Td>
    </>} />
}

export function FundingReportsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [tab, setTab] = useState<FundingReportType>('active')
  const [loading, setLoading] = useState(true)
  const [data, setData] = useState<any>(null)
  const [err, setErr] = useState('')
  const { branches, branchId, setBranchId } = useBranchFilter(me)

  // per-tab filter state
  const [days, setDays] = useState(30)
  const [monthlyStatus, setMonthlyStatus] = useState<'' | 'Pending' | 'Paid'>('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [wdKind, setWdKind] = useState<'' | 'matured' | 'premature'>('')
  const [customers, setCustomers] = useState<Customer[]>([])
  const [customerLabel, setCustomerLabel] = useState('')
  const [customerId, setCustomerId] = useState<number | undefined>()

  useEffect(() => { customersApi.list().then(setCustomers).catch(() => setCustomers([])) }, [])

  const buildParams = (): Record<string, any> => ({
    branchId,
    ...(tab === 'maturing-soon' ? { days } :
      tab === 'monthly-interest' ? { status: monthlyStatus || undefined } :
      tab === 'interest-paid' ? { from: fromDate || undefined, to: toDate || undefined } :
      tab === 'withdrawals' ? { from: fromDate || undefined, to: toDate || undefined, kind: wdKind || undefined } :
      tab === 'deposit-history' ? { customerId: customerId || undefined } :
      tab === 'customer-funding-history' ? { customerId } : {}),
  })

  const load = () => {
    setErr('')
    if (tab === 'customer-funding-history' && !customerId) { setData(null); setLoading(false); return }
    setLoading(true)
    fundingReportsApi.get(tab, buildParams()).then(setData).catch((ex) => { setErr(getErrorText(ex, 'Failed to load report')); setData(null) }).finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [tab, days, monthlyStatus, fromDate, toDate, wdKind, customerId, branchId]) // eslint-disable-line react-hooks/exhaustive-deps

  const tabLabel = TABS.find((t) => t.id === tab)?.label || tab
  const exportExcel = () => {
    if (!data) return
    if (tab === 'customer-funding-history') {
      void exportReportToExcel({
        sheetName: 'Customer Funding History', reportTitle: 'Customer Funding History', subtitle: customerLabel || undefined,
        sections: [
          { title: 'Deposits', columns: DEPOSIT_COLS, rows: depositRows(data.deposits || []), emptyText: 'No deposits' },
          { title: 'Fixed Deposits', columns: FD_COLS, rows: fdRows(data.fixedDeposits || []), emptyText: 'No fixed deposits' },
        ],
      }, `customer-funding-history-${today()}.xlsx`)
      return
    }
    const rows: any[] = data || []
    if (tab === 'active' || tab === 'maturing-soon' || tab === 'matured') {
      void exportFlatTableToExcel({ reportTitle: tabLabel, columns: FD_COLS, rows: fdRows(rows), totalRow: { customer: 'Total', principal: rows.reduce((a, r) => a + Number(r.principalAmount || 0), 0) }, emptyText: 'No records' }, `${tab}-${today()}.xlsx`)
    } else if (tab === 'interest-paid') {
      void exportFlatTableToExcel({ reportTitle: tabLabel, columns: INTEREST_PAID_COLS, rows: interestPaidRows(rows), totalRow: { customer: 'Total', amount: rows.reduce((a, r) => a + Number(r.paidAmount || 0), 0) }, emptyText: 'No records' }, `${tab}-${today()}.xlsx`)
    } else if (tab === 'monthly-interest') {
      void exportFlatTableToExcel({ reportTitle: tabLabel, columns: INTEREST_COLS, rows: interestRows(rows), totalRow: { customer: 'Total', amount: rows.reduce((a, r) => a + Number(r.amount || 0), 0) }, emptyText: 'No records' }, `${tab}-${today()}.xlsx`)
    } else if (tab === 'deposit-history') {
      void exportFlatTableToExcel({ reportTitle: tabLabel, columns: DEPOSIT_COLS, rows: depositRows(rows), totalRow: { customer: 'Total', amount: rows.reduce((a, r) => a + Number(r.amount || 0), 0) }, emptyText: 'No records' }, `${tab}-${today()}.xlsx`)
    } else if (tab === 'withdrawals') {
      const sum = (k: string) => rows.reduce((a, r) => a + Number(r[k] || 0), 0)
      void exportFlatTableToExcel({ reportTitle: tabLabel, columns: WITHDRAWAL_COLS, rows: withdrawalRows(rows), totalRow: { customer: 'Total', principal: sum('principalAmount'), interest: sum('interestAmount'), clawback: sum('prematureClawback'), total: sum('totalAmount') }, emptyText: 'No records' }, `${tab}-${today()}.xlsx`)
    } else if (tab === 'renewal-history' || tab === 'capital-enhancements') {
      void exportFlatTableToExcel({ reportTitle: tabLabel, columns: RENEWAL_COLS, rows: renewalRows(rows), emptyText: 'No records' }, `${tab}-${today()}.xlsx`)
    }
  }
  const exportPdf = async () => downloadBlob(await fundingReportsApi.pdf(tab, buildParams()), `${tab}-${today()}.pdf`)

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Funding Reports" icon="chart"
        actions={data && <ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />
      <Card pad={0} style={{ marginBottom: 16 }}>
        <div className="row gap-1 wrap" style={{ padding: 14 }}>
          {TABS.map((t) => <Btn key={t.id} size="sm" variant="ghost" active={tab === t.id} onClick={() => setTab(t.id)}>{t.label}</Btn>)}
        </div>
      </Card>
      <Card style={{ marginBottom: 16 }}><div className="row gap-3 wrap"><BranchFilter user={me} branches={branches} branchId={branchId} onChange={setBranchId} /></div></Card>

      {tab === 'maturing-soon' && (
        <Card style={{ marginBottom: 16 }}><Field label="Within Days"><Input type="number" value={days} onChange={(e) => setDays(Number(e.target.value) || 30)} style={{ maxWidth: 160 }} /></Field></Card>
      )}
      {tab === 'monthly-interest' && (
        <Card style={{ marginBottom: 16 }}><Field label="Status"><Select value={monthlyStatus} onChange={(e) => setMonthlyStatus(e.target.value as any)} style={{ maxWidth: 200 }}>
          <option value="">All</option><option value="Pending">Pending</option><option value="Paid">Paid</option>
        </Select></Field></Card>
      )}
      {(tab === 'interest-paid' || tab === 'withdrawals') && (
        <Card style={{ marginBottom: 16 }}>
          <div className="row gap-3 wrap">
            <DateRangeSelect from={fromDate} to={toDate} onChange={(f, t) => { setFromDate(f); setToDate(t) }} style={{ minWidth: 190 }} />
            <Field label="From"><Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} /></Field>
            <Field label="To"><Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} /></Field>
            {tab === 'withdrawals' && (
              <Field label="Type"><Select value={wdKind} onChange={(e) => setWdKind(e.target.value as any)} style={{ minWidth: 160 }}>
                <option value="">All</option><option value="matured">Matured</option><option value="premature">Premature</option>
              </Select></Field>
            )}
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
        {loading ? <LoadingBlock /> :
         err ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--bad)', whiteSpace: 'pre-line' }}>{err}</div> :
         tab === 'customer-funding-history' && !customerId ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Pick a customer to view their funding history.</div> :
         (tab === 'active' || tab === 'maturing-soon' || tab === 'matured') ? <FdRows rows={data || []} /> :
         tab === 'monthly-interest' ? <InterestPaymentRows rows={data || []} /> :
         tab === 'interest-paid' ? <InterestPaidRows rows={data || []} /> :
         tab === 'withdrawals' ? <WithdrawalRows rows={data || []} /> :
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
