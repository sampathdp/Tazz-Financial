/* Tazz Investment & Credit — Payment Receipt: standalone loan-payment intake.
   Look up any active loan by NIC, Loan ID, or customer name, see its upcoming
   installments, then make a payment against the loan as a whole — the amount
   is allocated server-side to the oldest unpaid installment first (partial
   payments carry the remainder forward — see LoanPaymentAllocator), so there's
   no per-installment "Pay" action. Skipping an installment is an admin-only
   action — see the Loan Adjustments screen.
   Full settlement lives in the Loan Summary card — Cashier-only, and doesn't
   close the loan or become printable until a Branch Manager approves it.
   Posts to the same loans/{loan}/payments endpoint the Active Loans > Receipts
   tab uses, so history and outstanding balances stay in sync either way. */
import { useEffect, useRef, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, BankSelect, SearchSelect, Table, Td, Modal, inputStyle, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  loansApi, loanPaymentsApi, loanPostDatedChequesApi, speedDraftsApi, speedDraftPaymentsApi, company,
  type Loan, type LoanPayment, type LoanSchedule, type PaymentMethod, type AuthUser, type PaymentAllocationSplit,
  type SpeedDraft, type SpeedDraftPayment, type SpeedDraftSchedule, type SpeedDraftPaymentType,
} from '../api'
import { Can, canApprove } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { useBranchFilter } from '../components/BranchFilter'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const outstandingOf = (loan: Loan) => {
  return Math.max(0, num(loan.summary?.arrears?.amountToBeCollected ?? loan.outstandingBalanceCache))
}
const balanceOf = (r: LoanSchedule) => Math.max(0, num(r.totalDue) - num(r.paidAmount))
const isCashier = (me?: AuthUser | null) => me?.role === 'cashier' || me?.role === 'admin'

const METHOD_OPTIONS: { value: PaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' },
  { value: 'Bank', label: 'Bank Transfer' },
  { value: 'Cheque', label: 'Cheque' },
]
const REF_LABEL: Record<PaymentMethod, string> = { Cash: 'Receipt No. (optional)', Bank: 'Transfer Reference', Cheque: 'Cheque No.' }
const scheduleTone: Record<string, 'green' | 'red' | 'amber' | 'neutral'> = { Paid: 'green', Partial: 'amber', Overdue: 'red', Pending: 'neutral' }

/** Payment History row status — mirrors LoanDetail's ReceiptsPanel classification. */
function PaymentStatusBadge({ p }: { p: LoanPayment }) {
  if (p.status === 'Voided') return <Badge tone="red" dot>Voided</Badge>
  if (p.pendingVoidRequest) return <Badge tone="amber" dot>Cancel Pending</Badge>
  if (p.pendingAllocationRequest) return <Badge tone="amber" dot>Allocation Pending Approval</Badge>
  if (p.approvalStatus === 'PendingApproval') return <Badge tone="amber" dot>Pending Approval</Badge>
  if (p.paymentMethod === 'Cheque' && p.clearanceStatus === 'Pending') return <Badge tone="amber" dot>Pending Clearance</Badge>
  return <Badge tone="green" dot>Recorded</Badge>
}

interface ReceiptData { payment: LoanPayment; loan: Loan; balanceBefore: number }

/* A Cheque payment (unlike Cash/Bank) does NOT apply to the balance immediately —
   it's held Pending until admin marks it Realized on Loan Adjustments' Cheque
   Clearance tab, which is when the amount actually lands in the loan (as of the
   cheque's bank check-in/clearing date, not this entry date). "Cheque Date" here
   is just the date printed on the cheque, captured for the audit trail. This is
   distinct from "Record Cheque" (Post Dated Cheques), which logs a future cheque
   the same way — also untouched until realized. */
function PaymentFields({ method, setMethod, paidAt, setPaidAt, backDated, setBackDated, refNo, setRefNo, nextReceipt, bankId, setBankId, chequeDate, setChequeDate, notes, setNotes }: {
  method: PaymentMethod; setMethod: (v: PaymentMethod) => void
  paidAt: string; setPaidAt: (v: string) => void
  backDated: boolean; setBackDated: (v: boolean) => void
  refNo: string; setRefNo: (v: string) => void
  nextReceipt: string
  bankId: number | undefined; setBankId: (v: number | undefined) => void
  chequeDate: string; setChequeDate: (v: string) => void
  notes: string; setNotes: (v: string) => void
}) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16 }}>
      <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
        {METHOD_OPTIONS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
      </Select></Field>
      <Field label="Date">
        <Input type="date" value={paidAt} max={todayIso()} disabled={!backDated} onChange={(e) => setPaidAt(e.target.value)} />
        <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
          <input type="checkbox" checked={backDated} onChange={(e) => {
            const checked = e.target.checked
            setBackDated(checked)
            if (!checked) setPaidAt(todayIso())
          }} />
          <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
        </label>
      </Field>
      {method === 'Cash'
        ? <Field label="Receipt No."><Input value={nextReceipt || '…'} readOnly disabled style={{ color: 'var(--tx-2)' }} /></Field>
        : <Field label={REF_LABEL[method]}><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>}
      {method === 'Cheque' && <>
        <Field label="Bank"><BankSelect value={bankId} onChange={setBankId} /></Field>
        <Field label="Cheque Date"><Input type="date" value={chequeDate} onChange={(e) => setChequeDate(e.target.value)} /></Field>
      </>}
      <Field label="Notes" full><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
    </div>
  )
}

const infoGridStyle: React.CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5 }
const sectionLabelStyle: React.CSSProperties = { fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 6 }

/** Read-only "everything about this loan" tab — a compact version of LoanDetail's
 *  Contract Summary + Security/Collateral panels, scoped for a cashier looking up
 *  a loan mid-payment rather than the full loan management workspace. */
function LoanDetailsPanel({ loan }: { loan: Loan }) {
  const d = loan.loanDetail
  const c = loan.customer
  const insurance = loan.insurance
  const hasReference = !!(d?.referenceName || d?.referenceIdNo || d?.referenceAddress)
  const statusEvents = (loan.statusEvents || []).slice().sort((a, b) => new Date(b.eventDate || 0).getTime() - new Date(a.eventDate || 0).getTime())

  return (
    <div className="col gap-3">
      <Card>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Customer Information</div>
        <div style={infoGridStyle}>
          <div><span className="t-2">Full Name: </span><b>{c?.fullName || '—'}</b></div>
          <div><span className="t-2">Customer Code: </span><b>{c?.code || '—'}</b></div>
          <div><span className="t-2">NIC No: </span><b>{c?.nicNo || '—'}</b></div>
          <div><span className="t-2">Passport No: </span><b>{c?.passportNo || '—'}</b></div>
          <div><span className="t-2">Driving License: </span><b>{c?.drivingLicenseNo || '—'}</b></div>
          <div><span className="t-2">Phone: </span><b>{c?.phone || '—'}</b></div>
          <div><span className="t-2">Email: </span><b>{c?.email || '—'}</b></div>
          <div><span className="t-2">Address: </span><b>{c?.address || '—'}</b></div>
          <div><span className="t-2">Occupation: </span><b>{c?.occupationType || '—'}</b></div>
          <div><span className="t-2">Monthly Income: </span><b>{c?.monthlyIncome != null ? money(c.monthlyIncome) : '—'}</b></div>
          <div><span className="t-2">Branch: </span><b>{loan.branch?.name || c?.branch?.name || '—'}</b></div>
          {!!loan.coApplicants?.length && (
            <div style={{ gridColumn: '1/-1' }}>
              <span className="t-2">Co-Applicants: </span>
              <b>{loan.coApplicants.map((ca) => ca.coApplicant?.fullName).filter(Boolean).join(', ')}</b>
            </div>
          )}
        </div>
      </Card>

      <Card>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Loan / Facility Details</div>
        <div style={infoGridStyle}>
          <div><span className="t-2">Loan Code: </span><b>{loan.code}</b></div>
          <div><span className="t-2">Status: </span><Badge tone="green" dot>{loan.status}</Badge></div>
          <div><span className="t-2">Product: </span><b>{d?.loanType?.name || '—'}</b></div>
          <div><span className="t-2">Principal Amount: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(loan.principalAmount)}</b></div>
          <div><span className="t-2">Down Payment: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(d?.downPayment)}</b></div>
          <div><span className="t-2">Term: </span><b>{loan.termMonths} months</b></div>
          <div><span className="t-2">Frequency: </span><b style={{ textTransform: 'capitalize' }}>{d?.installmentFrequency || '—'}</b></div>
          <div><span className="t-2">Interest Method: </span><b>{d?.interestMethod === 'flat' ? 'Flat' : d?.interestMethod === 'reducing_balance' ? 'Reducing Balance' : '—'}</b></div>
          <div><span className="t-2">Interest Rate: </span><b>{loan.interestRatePercent != null ? `${loan.interestRatePercent}% ${loan.ratePeriod || ''}` : '—'}</b></div>
          <div><span className="t-2">Purpose: </span><b>{d?.purpose || '—'}</b></div>
          <div><span className="t-2">Application Date: </span><b>{dateOnly(d?.applicationDate)}</b></div>
          <div><span className="t-2">First Installment Date: </span><b>{dateOnly(d?.firstInstallmentDate)}</b></div>
          <div><span className="t-2">Disbursed At: </span><b>{dateOnly(loan.disbursedAt)}</b></div>
          <div><span className="t-2">Disbursed Amount: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{loan.disbursedAmount != null ? money(loan.disbursedAmount) : '—'}</b></div>
          <div><span className="t-2">Marketing Executive: </span><b>{d?.loanOfficer?.name || '—'}</b></div>
          <div><span className="t-2">Follow Up Officer: </span><b>{d?.recoveryOfficer?.name || '—'}</b></div>
          {d?.vehicleNo && <div><span className="t-2">Vehicle No: </span><b>{d.vehicleNo}</b></div>}
        </div>
      </Card>

      {!!loan.summary?.dueBreakdown?.length && (
        <Card pad={0}>
          <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Due Type Breakdown — Interest, OD & Charges</div>
          <Table cols={[{ label: 'Due Type' }, { label: 'Due Amount', align: 'right' }, { label: 'Paid Amount', align: 'right' }, { label: 'Balance', align: 'right' }, { label: 'Recovery %', align: 'center' }]}
            rows={loan.summary.dueBreakdown} empty="No charges recorded"
            render={(r) => <>
              <Td style={{ fontWeight: 600 }}>{r.type}</Td>
              <Td mono align="right">{money(r.due)}</Td>
              <Td mono align="right" c="var(--ok)">{money(r.paid)}</Td>
              <Td mono align="right" c={r.balance > 0 ? 'var(--bad)' : 'var(--tx-1)'}>{money(r.balance)}</Td>
              <Td align="center"><Badge tone={r.recoveryPct >= 100 ? 'green' : r.recoveryPct > 0 ? 'amber' : 'red'} dot>{r.recoveryPct.toFixed(2)}%</Badge></Td>
            </>} />
        </Card>
      )}

      {!!insurance && (
        <Card>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Insurance Policy</div>
          <div style={infoGridStyle}>
            <div><span className="t-2">Company: </span><b>{insurance.insuranceCompany || '—'}</b></div>
            <div><span className="t-2">Policy No: </span><b>{insurance.policyNo || '—'}</b></div>
            <div><span className="t-2">Amount: </span><b>{insurance.amount != null ? money(insurance.amount) : '—'}</b></div>
            <div><span className="t-2">Contact Person: </span><b>{insurance.contactPerson || '—'}</b></div>
            <div><span className="t-2">Coverage: </span><b>{insurance.coverage || '—'}</b></div>
            {insurance.remarks && <div style={{ gridColumn: '1/-1' }}><span className="t-2">Remark: </span><b>{insurance.remarks}</b></div>}
          </div>
        </Card>
      )}

      {(hasReference || !!loan.guarantors?.length || !!loan.collateral?.length) && (
        <Card>
          <div className="eyebrow" style={{ marginBottom: 12 }}>Security, Collateral & Guarantors</div>
          {hasReference && (
            <div style={{ marginBottom: 14 }}>
              <div className="t-2" style={sectionLabelStyle}>Reference Person</div>
              <div style={infoGridStyle}>
                <div><span className="t-2">Name: </span><b>{d?.referenceName || '—'}</b></div>
                <div><span className="t-2">NIC / Other ID No: </span><b>{d?.referenceIdNo || '—'}</b></div>
                <div><span className="t-2">Address: </span><b>{d?.referenceAddress || '—'}</b></div>
              </div>
            </div>
          )}
          {!!loan.guarantors?.length && (
            <div style={{ marginBottom: 14 }}>
              <div className="t-2" style={sectionLabelStyle}>Guarantors</div>
              {loan.guarantors.map((g, i) => (
                <div key={i} className="row between" style={{ padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 12.5 }}>
                  <span>{g.guarantor?.fullName}{g.guarantor?.nicNo ? ` (${g.guarantor.nicNo})` : ''}</span>
                  <span className="t-2" style={{ fontFamily: 'JetBrains Mono' }}>{money(g.guaranteedAmount)}</span>
                </div>
              ))}
            </div>
          )}
          {!!loan.collateral?.length && (
            <div>
              <div className="t-2" style={sectionLabelStyle}>Collateral / Equipment</div>
              {loan.collateral.map((col, i) => {
                const v = col.vehicleDetail
                const vehicleBits = [
                  v?.vehicleType, v?.makeModel,
                  v?.registrationNo && `Reg: ${v.registrationNo}`,
                  v?.chassisNo && `Chassis: ${v.chassisNo}`,
                  v?.engineNo && `Engine: ${v.engineNo}`,
                  v?.yearOfManufacture && `Yr: ${v.yearOfManufacture}`,
                ].filter(Boolean)
                return (
                  <div key={i} style={{ padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 12.5 }}>
                    <div className="row between"><b>{col.collateralCategory || col.collateralType || 'Collateral'}</b><span className="t-2" style={{ fontFamily: 'JetBrains Mono' }}>{col.estimatedValue != null ? money(col.estimatedValue) : '—'}</span></div>
                    <div className="t-2">{col.description}</div>
                    {vehicleBits.length > 0 && <div className="t-2">{vehicleBits.join(' · ')}</div>}
                  </div>
                )
              })}
            </div>
          )}
        </Card>
      )}

      {!!statusEvents.length && (
        <Card>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Status Events</div>
          {statusEvents.map((e) => (
            <div key={e.id} className="row between" style={{ padding: '6px 0', borderBottom: '1px solid var(--line-soft)', fontSize: 12.5 }}>
              <span><Badge tone="amber" dot>{e.eventType}</Badge> {e.notes && <span className="t-2">— {e.notes}</span>}</span>
              <span className="t-2">{dateOnly(e.eventDate)}</span>
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}

/* ---------- Ledger Card (computed, client-side) — mirrors LoanDetail's LedgerCard. ---------- */
function LedgerCard({ loan }: { loan: Loan }) {
  type Row = { date: string; particulars: string; debit: number; credit: number; typeOrder: number }
  const rows: Row[] = []

  // Only the due side is logged here — a charge's paid_amount always comes from a
  // real LoanPayment (see LoanPaymentAllocator::applyAllocation), which is already
  // its own Receipt row below. Adding a second "charge paid" row here would credit
  // that same money twice and understate the running balance.
  for (const c of loan.charges || []) rows.push({ date: c.chargedAt, particulars: `${c.category?.name || 'Category'} charge`, debit: num(c.dueAmount), credit: 0, typeOrder: 1 })
  for (const s of loan.schedules || []) rows.push({ date: s.dueDate, particulars: `Installment #${s.installmentNo} due`, debit: num(s.totalDue), credit: 0, typeOrder: 2 })
  for (const w of loan.waivers || []) rows.push({ date: w.waivedAt, particulars: `${w.waiverType} waived${w.reason ? ` — ${w.reason}` : ''}`, debit: 0, credit: num(w.amount), typeOrder: 3 })
  for (const p of loan.payments || []) {
    if (p.status === 'Voided' || p.approvalStatus === 'PendingApproval' || (p.paymentMethod === 'Cheque' && p.clearanceStatus === 'Pending')) continue
    rows.push({ date: p.paidAt, particulars: `Receipt ${p.code} (${p.paymentMethod})`, debit: 0, credit: num(p.amount), typeOrder: 4 })
  }

  rows.sort((a, b) => {
    const dCmp = a.date.substring(0, 10).localeCompare(b.date.substring(0, 10))
    if (dCmp !== 0) return dCmp
    return a.typeOrder - b.typeOrder
  })
  let balance = 0
  const withBalance = rows.map((r) => { balance += r.debit - r.credit; return { ...r, balance } })

  return (
    <Card pad={0}>
      <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Ledger Card</div>
      <Table cols={[{ label: 'Date' }, { label: 'Particulars' }, { label: 'Debit', align: 'right' }, { label: 'Credit', align: 'right' }, { label: 'Balance', align: 'right' }]}
        rows={withBalance} empty="No ledger entries yet"
        render={(r) => <>
          <Td>{dateOnly(r.date)}</Td>
          <Td>{r.particulars}</Td>
          <Td mono align="right">{r.debit ? money(r.debit) : '—'}</Td>
          <Td mono align="right">{r.credit ? money(r.credit) : '—'}</Td>
          <Td mono align="right">{money(r.balance)}</Td>
        </>} />
    </Card>
  )
}

export function PaymentReceiptScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [loans, setLoans] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [brand, setBrand] = useState({ name: '', tagline: '' })

  const [selected, setSelected] = useState<Loan | null>(null)
  const [loadingLoan, setLoadingLoan] = useState(false)

  // Make Payment modal — applies to the whole schedule, allocated server-side
  // oldest-unpaid-installment-first (see LoanPaymentAllocator).
  const [payOpen, setPayOpen] = useState(false)
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<PaymentMethod>('Cash')
  // Roles that work across branches can take the cash at another branch's counter; the two branches then settle through the inter-branch accounts.
  const { canFilter: canCollectElsewhere, branches: allBranches } = useBranchFilter(me)
  const [collectedBranchId, setCollectedBranchId] = useState<number | undefined>(undefined)
  const [paidAt, setPaidAt] = useState(todayIso())
  const [payBackDated, setPayBackDated] = useState(false)
  const [refNo, setRefNo] = useState('')
  const [payBankId, setPayBankId] = useState<number | undefined>(undefined)
  const [payChequeDate, setPayChequeDate] = useState(todayIso())
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)
  const recordingRef = useRef(false)
  const [err, setErr] = useState('')
  // Manual allocation — cashier splits the amount across buckets instead of the
  // automatic waterfall; held PendingApproval until a top-level user approves it
  // (see LoanPaymentController::approveAllocationRequest()).
  const [allocationMode, setAllocationMode] = useState<'auto' | 'manual'>('auto')
  // When on, the four bucket inputs below hold a percentage of the payment amount
  // instead of a direct figure — converted to amount via pctToAmt() before it's
  // compared to due or sent to the server (the server only ever sees amounts).
  const [allocByPercent, setAllocByPercent] = useState(false)
  const [allocInterest, setAllocInterest] = useState('')
  const [allocCapital, setAllocCapital] = useState('')
  const [allocOdCharge, setAllocOdCharge] = useState('')
  const [allocOtherCharges, setAllocOtherCharges] = useState('')
  // What's actually due per bucket right now — fetched fresh whenever the Make
  // Payment modal is open with a date set, so the manual-split caps shown match
  // what store() will validate against (see LoanPaymentAllocator::outstandingByBucket()).
  const [allocDue, setAllocDue] = useState<PaymentAllocationSplit | null>(null)

  // Full-settlement modal
  const [settleOpen, setSettleOpen] = useState(false)
  const [settleMethod, setSettleMethod] = useState<PaymentMethod>('Cash')
  const [settlePaidAt, setSettlePaidAt] = useState(todayIso())
  const [settleBackDated, setSettleBackDated] = useState(false)
  const [settleRefNo, setSettleRefNo] = useState('')
  const [settleBankId, setSettleBankId] = useState<number | undefined>(undefined)
  const [settleChequeDate, setSettleChequeDate] = useState(todayIso())
  const [settleNotes, setSettleNotes] = useState('')
  const [settleBusy, setSettleBusy] = useState(false)
  const [settleErr, setSettleErr] = useState('')

  // Record-cheque modal (Post Dated Cheques) — a record only, never applied to the balance.
  const [chequeOpen, setChequeOpen] = useState(false)
  const [chequeNo, setChequeNo] = useState('')
  const [rcBankId, setRcBankId] = useState<number | undefined>(undefined)
  const [rcChequeDate, setRcChequeDate] = useState(todayIso())
  const [chequeAmount, setChequeAmount] = useState('')
  const [chequeBusy, setChequeBusy] = useState(false)
  const [chequeErr, setChequeErr] = useState('')

  // Preview of the receipt number the next Cash payment on this loan would get —
  // just for display; the real number is only assigned when the payment is created.
  const [nextReceipt, setNextReceipt] = useState('')

  const [receipt, setReceipt] = useState<ReceiptData | null>(null)
  const [panelTab, setPanelTab] = useState<'history' | 'schedule' | 'details' | 'ledger'>('history')
  const [scheduleTab, setScheduleTab] = useState<'all' | 'paid' | 'unpaid'>('unpaid')
  const [viewPayment, setViewPayment] = useState<LoanPayment | null>(null)

  // Pending-payment worklist shown before a loan is picked — every Active loan
  // still carrying a balance, filtered by loan (code/customer/NIC/product) and
  // by Disbursed Date range, mirroring the pattern used on Overdue Loans.
  const [listSearch, setListSearch] = useState('')
  const [listFrom, setListFrom] = useState('')
  const [listTo, setListTo] = useState('')

  const refresh = () => {
    setLoading(true)
    loansApi.list().then(setLoans).catch(() => setLoans([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
  }, [])

  // Only Cash shows a receipt-number preview (Bank/Cheque use a manually-entered
  // external reference instead — see REF_LABEL), so skip the fetch otherwise.
  useEffect(() => {
    const activeMethod = payOpen ? method : settleOpen ? settleMethod : null
    if (!selected || activeMethod !== 'Cash') { setNextReceipt(''); return }
    let cancelled = false
    loanPaymentsApi.nextReceiptNo(selected.id, 'Cash').then((code) => { if (!cancelled) setNextReceipt(code) }).catch(() => { if (!cancelled) setNextReceipt('') })
    return () => { cancelled = true }
  }, [selected, payOpen, method, settleOpen, settleMethod])

  // Fetches the per-bucket breakdown as of the payment's own date whenever the
  // Make Payment modal is open — used both to display the breakdown and to cap
  // each manual-split field, matching what store() will validate against.
  useEffect(() => {
    if (!selected || !payOpen) { setAllocDue(null); return }
    let cancelled = false
    loanPaymentsApi.outstandingByBucket(selected.id, paidAt || undefined).then((d) => { if (!cancelled) setAllocDue(d) }).catch(() => { if (!cancelled) setAllocDue(null) })
    return () => { cancelled = true }
  }, [selected, payOpen, paidAt])

  const installmentAmountOf = (loan: Loan) => (loan.schedules?.length ? num(loan.schedules[0].totalDue) : null)
  const interestAmountOf = (loan: Loan) => loan.summary?.dueBreakdown?.find((r) => r.type === 'Interest')?.due ?? null
  // Rental + other-charge + default (OD) arrears on installments already past their
  // due date, net of waivers and overpayment — see LoanSummary::$balancePrePayment.
  const arrearsAmountOf = (loan: Loan) => (loan.summary?.arrears ? num(loan.summary.arrears.balancePrePayment) : null)
  const payable = loans.filter((l) => l.status === 'Active')
  const disbursedDate = (l: Loan) => (l.disbursedAt ? l.disbursedAt.slice(0, 10) : '')
  const pendingPayment = payable.filter((l) => num(l.totalDueSum) - num(l.paidSum) > 0)
  const listQ = listSearch.trim().toLowerCase()
  const filteredPending = pendingPayment.filter((l) => {
    if (listFrom && disbursedDate(l) < listFrom) return false
    if (listTo && disbursedDate(l) > listTo) return false
    if (!listQ) return true
    return [l.code, l.customer?.fullName, l.customer?.nicNo, l.loanDetail?.loanType?.name].some((v) => (v || '').toLowerCase().includes(listQ))
  })
  const todayLabel = new Date().toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
  const outstanding = selected ? outstandingOf(selected) : 0
  const totalPaid = (selected?.schedules || []).reduce((s, r) => s + num(r.paidAmount), 0)
  const pendingSettlement = selected?.payments?.find((p) => p.paymentType === 'Settlement' && p.approvalStatus === 'PendingApproval')
  const allSchedules = (selected?.schedules || []).slice().sort((a, b) => a.installmentNo - b.installmentNo)
  const paymentHistory = (selected?.payments || []).slice().sort((a, b) => new Date(b.paidAt).getTime() - new Date(a.paidAt).getTime())
  const scheduleRows = scheduleTab === 'paid' ? allSchedules.filter((s) => s.status === 'Paid')
    : scheduleTab === 'unpaid' ? allSchedules.filter((s) => s.status !== 'Paid')
    : allSchedules
  const locked = !!pendingSettlement

  const resetPayForm = () => {
    setPayOpen(false); setCollectedBranchId(undefined); setAmount(''); setMethod('Cash'); setPaidAt(todayIso()); setPayBackDated(false); setRefNo(''); setPayBankId(undefined); setPayChequeDate(todayIso()); setNotes(''); setErr('')
    setAllocationMode('auto'); setAllocByPercent(false); setAllocInterest(''); setAllocCapital(''); setAllocOdCharge(''); setAllocOtherCharges('')
  }
  const resetSettleForm = () => { setSettleOpen(false); setSettleMethod('Cash'); setSettlePaidAt(todayIso()); setSettleBackDated(false); setSettleRefNo(''); setSettleBankId(undefined); setSettleChequeDate(todayIso()); setSettleNotes(''); setSettleErr('') }
  const resetChequeForm = () => { setChequeOpen(false); setChequeNo(''); setRcBankId(undefined); setRcChequeDate(todayIso()); setChequeAmount(''); setChequeErr('') }

  const pickLoan = async (_n: string, opt?: Loan) => {
    resetPayForm(); resetSettleForm(); resetChequeForm(); setScheduleTab('unpaid'); setPanelTab('history')
    if (!opt) { setSelected(null); return }
    setLoadingLoan(true)
    try { setSelected(await loansApi.get(opt.id)) }
    catch { setSelected(opt) }
    finally { setLoadingLoan(false) }
  }

  const reloadSelected = async () => {
    if (!selected) return
    try { setSelected(await loansApi.get(selected.id)) } catch { /* keep stale copy */ }
  }

  const openPay = () => {
    setPayOpen(true); setCollectedBranchId(undefined); setAmount(''); setMethod('Cash'); setPaidAt(todayIso()); setPayBackDated(false); setRefNo(''); setPayBankId(undefined); setPayChequeDate(todayIso()); setNotes(''); setErr('')
    setAllocationMode('auto'); setAllocByPercent(false); setAllocInterest(''); setAllocCapital(''); setAllocOdCharge(''); setAllocOtherCharges(''); setAllocDue(null)
  }

  const allocTarget = Number(amount || 0)
  const pctToAmt = (v: string) => allocByPercent ? (num(v) / 100) * allocTarget : num(v)
  const allocSplit = { interest: pctToAmt(allocInterest), capital: pctToAmt(allocCapital), odCharge: pctToAmt(allocOdCharge), otherCharges: pctToAmt(allocOtherCharges) }
  const allocTotal = allocSplit.interest + allocSplit.capital + allocSplit.odCharge + allocSplit.otherCharges
  const toggleAllocUnit = (pct: boolean) => {
    setAllocByPercent(pct); setAllocInterest(''); setAllocCapital(''); setAllocOdCharge(''); setAllocOtherCharges('')
  }
  const allocRows: { key: string; label: string; due: number; value: string; set: (v: string) => void }[] = [
    { key: 'interest', label: 'Interest', due: allocDue?.interest ?? 0, value: allocInterest, set: setAllocInterest },
    { key: 'capital', label: 'Capital', due: allocDue?.capital ?? 0, value: allocCapital, set: setAllocCapital },
    { key: 'odCharge', label: 'OD Charges', due: allocDue?.odCharge ?? 0, value: allocOdCharge, set: setAllocOdCharge },
    { key: 'otherCharges', label: 'Other Charges', due: allocDue?.otherCharges ?? 0, value: allocOtherCharges, set: setAllocOtherCharges },
  ]

  const record = async () => {
    if (!selected || recordingRef.current) return
    if (!amount || Number(amount) <= 0) { setErr('Enter a payment amount.'); return }
    if (method === 'Cheque' && (!payBankId || !payChequeDate)) { setErr('Bank and Cheque Date are required for a cheque payment.'); return }
    if (allocationMode === 'manual' && Math.abs(allocTotal - Number(amount)) > 0.01) { setErr('The allocation split must add up to the payment amount.'); return }
    // Synchronous guard: `busy` state only updates after a re-render, so a fast double-click slips past it.
    recordingRef.current = true
    setBusy(true); setErr('')
    try {
      const balanceBefore = outstandingOf(selected)
      const payment = await loanPaymentsApi.create(selected.id, {
        amount, paymentMethod: method, paidAt, receiptNo: refNo || undefined,
        bankId: method === 'Cheque' ? payBankId : undefined,
        chequeDate: method === 'Cheque' ? payChequeDate : undefined,
        notes: notes || undefined,
        collectedBranchId: method === 'Cash' ? collectedBranchId : undefined,
        allocationMode: allocationMode === 'manual' ? 'manual' : undefined,
        allocations: allocationMode === 'manual' ? allocSplit : undefined,
      })
      if (allocationMode === 'manual') {
        resetPayForm()
        notify.success('Allocation request submitted', 'Awaiting top-level approval')
        await reloadSelected()
        refresh()
        return
      }
      const fresh = await loansApi.get(selected.id)
      setReceipt({ payment, loan: fresh, balanceBefore })
      refresh()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Payment failed'))
    } finally { recordingRef.current = false; setBusy(false) }
  }

  const submitSettlement = async () => {
    if (!selected) return
    if (settleMethod === 'Cheque' && (!settleBankId || !settleChequeDate)) { setSettleErr('Bank and Cheque Date are required for a cheque settlement.'); return }
    setSettleBusy(true); setSettleErr('')
    try {
      await loanPaymentsApi.create(selected.id, {
        amount: outstanding, paymentMethod: settleMethod, paidAt: settlePaidAt,
        receiptNo: settleRefNo || undefined,
        bankId: settleMethod === 'Cheque' ? settleBankId : undefined,
        chequeDate: settleMethod === 'Cheque' ? settleChequeDate : undefined,
        notes: settleNotes || undefined, isSettlement: true,
      })
      resetSettleForm()
      notify.success('Settlement request submitted', 'Awaiting Branch Manager approval')
      await reloadSelected()
    } catch (ex: any) {
      setSettleErr(getErrorText(ex, 'Could not submit the settlement request.'))
    } finally { setSettleBusy(false) }
  }

  const submitCheque = async () => {
    if (!selected) return
    if (!chequeNo || !rcBankId || !chequeAmount) { setChequeErr('Cheque no., bank, and amount are required.'); return }
    setChequeBusy(true); setChequeErr('')
    try {
      await loanPostDatedChequesApi.create(selected.id, { chequeNo, bankId: rcBankId, chequeDate: rcChequeDate, amount: chequeAmount })
      resetChequeForm()
      notify.success('Cheque recorded', 'Track it on the loan\'s Post Dated Cheques tab')
    } catch (ex: any) {
      setChequeErr(getErrorText(ex, 'Could not record the cheque.'))
    } finally { setChequeBusy(false) }
  }

  const approveSettlement = async () => {
    if (!selected || !pendingSettlement) return
    setBusy(true)
    try {
      const balanceBefore = num(pendingSettlement.amount)
      const approved = await loanPaymentsApi.approveSettlement(pendingSettlement.id)
      // Maker-checker: with a 2-approver type the first approval doesn't close
      // the loan yet — only show the receipt once it's fully Approved.
      if (approved.approvalStatus === 'Approved') {
        const fresh = await loansApi.get(selected.id)
        setReceipt({ payment: approved, loan: fresh, balanceBefore })
      } else {
        notify.success('Approved', 'Awaiting another approver')
        await reloadSelected()
      }
      refresh()
    } catch (ex: any) {
      notify.error('Approval failed', getErrorText(ex))
    } finally { setBusy(false) }
  }

  const rejectSettlement = async () => {
    if (!pendingSettlement) return
    const reason = await notify.prompt({ title: 'Reject this settlement request?', msg: 'Give a reason — the cashier can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try {
      await loanPaymentsApi.rejectSettlement(pendingSettlement.id, reason || undefined)
      notify.success('Settlement request rejected')
      await reloadSelected()
    } catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  const voidPayment = async (payment: LoanPayment) => {
    const reason = await notify.prompt({ title: `Request cancel for receipt ${payment.code}?`, msg: 'Needs approval before it reverses this payment from the schedule, ledger, and cashbook.', placeholder: 'Reason for cancelling', danger: true, okText: 'Send Request' })
    if (reason === null) return
    try {
      await loanPaymentsApi.requestVoid(payment.id, reason || undefined)
      notify.success('Cancel request sent', 'Awaiting approval')
      setViewPayment(null)
      await reloadSelected()
      refresh()
    } catch (ex: any) { notify.error('Request failed', getErrorText(ex)) }
  }

  const approveVoid = async (payment: LoanPayment) => {
    if (!payment.pendingVoidRequest) return
    try {
      await loanPaymentsApi.approveVoidRequest(payment.pendingVoidRequest.id)
      notify.success('Approved')
      setViewPayment(null)
      await reloadSelected()
      refresh()
    } catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }

  const rejectVoid = async (payment: LoanPayment) => {
    if (!payment.pendingVoidRequest) return
    const reason = await notify.prompt({ title: 'Reject this cancel request?', msg: 'Give a reason — the requester can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try {
      await loanPaymentsApi.rejectVoidRequest(payment.pendingVoidRequest.id, reason || undefined)
      notify.success('Cancel request rejected')
      setViewPayment(null)
      await reloadSelected()
      refresh()
    } catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  const approveAllocation = async (payment: LoanPayment) => {
    if (!payment.pendingAllocationRequest) return
    try {
      await loanPaymentsApi.approveAllocationRequest(payment.pendingAllocationRequest.id)
      notify.success('Approved')
      setViewPayment(null)
      await reloadSelected()
      refresh()
    } catch (ex: any) { notify.error('Approval failed', getErrorText(ex)) }
  }

  const rejectAllocation = async (payment: LoanPayment) => {
    if (!payment.pendingAllocationRequest) return
    const reason = await notify.prompt({ title: 'Reject this allocation request?', msg: 'Give a reason — the cashier can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try {
      await loanPaymentsApi.rejectAllocationRequest(payment.pendingAllocationRequest.id, reason || undefined)
      notify.success('Allocation request rejected')
      setViewPayment(null)
      await reloadSelected()
      refresh()
    } catch (ex: any) { notify.error('Rejection failed', getErrorText(ex)) }
  }

  const startOver = () => {
    setReceipt(null); setSelected(null)
    resetPayForm(); resetSettleForm(); resetChequeForm()
  }

  if (receipt) {
    const { payment, loan, balanceBefore } = receipt
    const balanceAfter = outstandingOf(loan)
    const pendingClearance = payment.paymentMethod === 'Cheque' && payment.clearanceStatus === 'Pending'
    // Equivalent installment count this payment covers (e.g. installment 100, pay 375 -> 3.75),
    // same fractional basis as the OD/arrears figures — not rounded to whole installments.
    const receiptInstallmentAmount = installmentAmountOf(loan)
    const installmentsCovered = receiptInstallmentAmount ? num(payment.amount) / receiptInstallmentAmount : null
    return (
      <div>
        <div className="row between wrap gap-2 no-print" style={{ marginBottom: 16 }}>
          <PageHead crumbs="Loan Management" title="Payment Receipt" icon="receipt"
            sub={payment.paymentType === 'Settlement' ? 'Settlement approved — loan closed. Print or issue the receipt below.'
              : pendingClearance ? 'Cheque recorded — held pending clearance, not yet applied to the balance. Print or issue the receipt below.'
              : 'Payment recorded — print or issue the receipt below.'} />
        </div>
        <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
          <Btn variant="primary" icon="print" onClick={() => window.print()}>Print Receipt</Btn>
          <Btn variant="solid" icon="plus" onClick={startOver}>New Payment</Btn>
        </div>

        <Card style={{ maxWidth: 560, margin: '0 auto', fontFamily: 'Outfit' }}>
          <div className="col gap-1" style={{ textAlign: 'center', marginBottom: 18, paddingBottom: 18, borderBottom: '1px dashed var(--line)' }}>
            <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 20 }}>{brand.name || 'Company'}</div>
            {brand.tagline && <div className="t-2" style={{ fontSize: 12 }}>{brand.tagline}</div>}
            <div style={{ fontWeight: 700, fontSize: 14, letterSpacing: '.08em', marginTop: 8 }}>{payment.paymentType === 'Settlement' ? 'FULL SETTLEMENT RECEIPT' : 'PAYMENT RECEIPT'}</div>
          </div>

          <div className="row between" style={{ marginBottom: 14, fontSize: 13 }}>
            <div><span className="t-2">Receipt No: </span><b>{payment.code}</b></div>
            <div><span className="t-2">Date: </span><b>{dateOnly(payment.paidAt)}</b></div>
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Received From</span><b>{loan.customer?.fullName}</b></div>
            <div className="row between"><span className="t-2">NIC No</span><b>{loan.customer?.nicNo || '—'}</b></div>
            <div className="row between"><span className="t-2">Loan / Contract No</span><b>{loan.code}</b></div>
            <div className="row between"><span className="t-2">Product</span><b>{loan.loanDetail?.loanType?.name || '—'}</b></div>
            {payment.paymentType === 'Settlement' && <div className="row between"><span className="t-2">Loan Status</span><b>Closed</b></div>}
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Payment Method</span><b>{METHOD_OPTIONS.find((m) => m.value === payment.paymentMethod)?.label || payment.paymentMethod}</b></div>
            {payment.receiptNo && <div className="row between"><span className="t-2">{REF_LABEL[payment.paymentMethod]}</span><b>{payment.receiptNo}</b></div>}
            {payment.paymentMethod === 'Cheque' && payment.bank?.name && <div className="row between"><span className="t-2">Bank</span><b>{payment.bank.name}</b></div>}
            {payment.paymentMethod === 'Cheque' && payment.chequeDate && <div className="row between"><span className="t-2">Cheque Date</span><b>{dateOnly(payment.chequeDate)}</b></div>}
            {payment.notes && <div className="row between"><span className="t-2">Notes</span><b>{payment.notes}</b></div>}
            {payment.paymentType === 'Settlement' && <div className="row between"><span className="t-2">Approved By</span><b>{payment.approver?.name || '—'}</b></div>}
          </div>

          {pendingClearance && (
            <div className="no-print" style={{ background: 'var(--warn-dim)', color: 'var(--warn)', border: '1px solid var(--warn)', padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5, marginBottom: 16 }}>
              This cheque is held pending clearance and has not been applied to the balance. Realize or reject it on the Cheque Management screen once it clears (or bounces) at the bank.
            </div>
          )}
          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 20 }}>
            <div className="row between"><span className="t-2">Balance Before</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(balanceBefore)}</span></div>
            <div className="row between"><span className="t-2">{pendingClearance ? 'Cheque Amount (pending)' : 'Amount Paid'}</span><span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(payment.amount)}</span></div>
            {!pendingClearance && installmentsCovered != null && (
              <div className="row between"><span className="t-2">Installments Paid</span><span style={{ fontFamily: 'JetBrains Mono' }}>{installmentsCovered.toFixed(2)}</span></div>
            )}
            <div className="row between" style={{ fontSize: 15, fontWeight: 700, paddingTop: 6, borderTop: '1px solid var(--line)' }}>
              <span>{pendingClearance ? 'Balance (unchanged until cleared)' : 'Balance After'}</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(balanceAfter)}</span>
            </div>
          </div>

          <div className="row between" style={{ fontSize: 12, marginTop: 30 }}>
            <div>Received By: <b>{payment.receiver?.name || me?.name || '—'}</b></div>
            <div>Signature: ______________</div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Payment Receipt" icon="receipt"
        sub="Look up a loan by NIC, Loan ID, or customer name, then pay an installment or settle the loan completely."
        actions={<div className="t-2" style={{ fontSize: 12.5 }}>Today: <b style={{ color: 'var(--tx-0)' }}>{todayLabel}</b></div>} />

      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Find Loan</div>
        <SearchSelect
          value={selected ? `${selected.code} — ${selected.customer?.fullName}` : ''}
          onChange={(n, opt) => pickLoan(n, opt)}
          options={payable}
          getLabel={(l: Loan) => `${l.code} — ${l.customer?.fullName}`}
          getSub={(l: Loan) => [l.customer?.nicNo, l.customer?.phone].filter(Boolean).join(' · ')}
          placeholder={loading ? 'Loading loans…' : 'Search by NIC, Loan ID, or customer name…'}
          disabled={loading}
        />
      </Card>

      {loadingLoan && <div style={{ padding: 30, textAlign: 'center', color: 'var(--tx-2)' }}>Loading loan…</div>}

      {selected && !loadingLoan && (
        <>
          <Card style={{ marginBottom: 16 }}>
            <div className="row between wrap gap-2" style={{ marginBottom: 14 }}>
              <div className="eyebrow">Loan Summary</div>
              <div className="row gap-2">
                <Can user={me} module="Loan Payments" action="Create">
                  {outstanding > 0 && !locked && (
                    <Btn variant="primary" size="sm" icon="check" onClick={openPay}>Make Payment</Btn>
                  )}
                  <Btn variant="solid" size="sm" icon="cheque" onClick={() => setChequeOpen(true)}>Record Cheque</Btn>
                </Can>
                {isCashier(me) && outstanding > 0 && !locked && (
                  <Btn variant="solid" size="sm" icon="flag" onClick={() => setSettleOpen(true)}>Settle Loan Completely</Btn>
                )}
              </div>
            </div>

            <div className="row wrap gap-3" style={{ alignItems: 'center', paddingBottom: 14, marginBottom: 14, borderBottom: '1px solid var(--line)' }}>
              <div>
                <div style={{ fontSize: 15, fontWeight: 700 }}>{selected.customer?.fullName}</div>
                <div className="t-2" style={{ fontSize: 11.5 }}>NIC {selected.customer?.nicNo || '—'}</div>
              </div>
              <div style={{ width: 1, height: 30, background: 'var(--line)' }} />
              <div>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em' }}>Loan Code</div>
                <div style={{ fontFamily: 'JetBrains Mono', fontWeight: 600, fontSize: 13 }}>{selected.code}</div>
              </div>
              <div style={{ width: 1, height: 30, background: 'var(--line)' }} />
              <div>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em' }}>Product</div>
                <div style={{ fontWeight: 600, fontSize: 13 }}>{selected.loanDetail?.loanType?.name || '—'}</div>
              </div>
              <div style={{ width: 1, height: 30, background: 'var(--line)' }} />
              <div>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 3 }}>Status</div>
                <Badge tone="green" dot>{selected.status}</Badge>
              </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12 }}>
              <div style={{ padding: '10px 14px', background: 'var(--bg-2)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em' }}>Interest Amount</div>
                <div style={{ fontFamily: 'JetBrains Mono', fontSize: 16, fontWeight: 700, marginTop: 3 }}>{interestAmountOf(selected) != null ? money(interestAmountOf(selected)) : '—'}</div>
              </div>
              <div style={{ padding: '10px 14px', background: 'var(--bg-2)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)' }}>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em' }}>Installment Amount</div>
                <div style={{ fontFamily: 'JetBrains Mono', fontSize: 16, fontWeight: 700, marginTop: 3 }}>{installmentAmountOf(selected) != null ? money(installmentAmountOf(selected)) : '—'}</div>
              </div>
              {(() => {
                const arrears = arrearsAmountOf(selected)
                const overdue = arrears != null && arrears > 0
                return (
                  <div style={{
                    padding: '10px 14px', borderRadius: 'var(--r-s)',
                    background: overdue ? 'var(--warn-dim)' : 'var(--bg-2)',
                    border: `1px solid ${overdue ? 'var(--warn)' : 'var(--line)'}`,
                  }}>
                    <div style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', color: overdue ? 'var(--warn)' : 'var(--tx-2)' }}>Arrears (as of {todayIso()})</div>
                    <div style={{ fontFamily: 'JetBrains Mono', fontSize: 16, fontWeight: 700, marginTop: 3, color: overdue ? 'var(--warn)' : 'var(--tx-1)' }}>{arrears != null ? money(arrears) : '—'}</div>
                  </div>
                )
              })()}
              <div style={{
                padding: '10px 14px', borderRadius: 'var(--r-s)',
                background: outstanding > 0 ? 'var(--bad-dim)' : 'var(--bg-2)',
                border: `1px solid ${outstanding > 0 ? 'var(--bad)' : 'var(--line)'}`,
              }}>
                <div style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', color: outstanding > 0 ? 'var(--bad)' : 'var(--tx-2)' }}>Outstanding Balance</div>
                <div style={{ fontFamily: 'JetBrains Mono', fontSize: 18, fontWeight: 700, marginTop: 3, color: outstanding > 0 ? 'var(--bad)' : 'var(--ok)' }}>{money(outstanding)}</div>
              </div>
            </div>
          </Card>

          {pendingSettlement && (
            <Card style={{ marginBottom: 16 }}>
              <div className="eyebrow" style={{ marginBottom: 12 }}>Full Settlement — Pending Approval</div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5, marginBottom: 16 }}>
                <div><span className="t-2">Amount: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(pendingSettlement.amount)}</b></div>
                <div><span className="t-2">Method: </span><b>{METHOD_OPTIONS.find((m) => m.value === pendingSettlement.paymentMethod)?.label}</b></div>
                <div><span className="t-2">Requested By: </span><b>{pendingSettlement.receiver?.name || '—'}</b></div>
                <div><span className="t-2">Requested At: </span><b>{dateOnly(pendingSettlement.createdAt || pendingSettlement.paidAt)}</b></div>
                {pendingSettlement.paymentMethod === 'Cheque' && pendingSettlement.bank?.name && <div><span className="t-2">Bank: </span><b>{pendingSettlement.bank.name}</b></div>}
                {pendingSettlement.paymentMethod === 'Cheque' && pendingSettlement.chequeDate && <div><span className="t-2">Cheque Date: </span><b>{dateOnly(pendingSettlement.chequeDate)}</b></div>}
              </div>
              {(() => {
                const req = pendingSettlement.pendingSettlementRequest
                const left = req ? req.requiredApprovals - (req.firstApprover ? 1 : 0) : 1
                const mayApprove = canApprove(me, 'payment_settlement')
                  && me?.id !== req?.requester?.id && me?.id !== req?.firstApprover?.id
                return mayApprove ? (
                  <div className="col gap-1">
                    <div className="row gap-2">
                      <Btn variant="primary" icon="check" onClick={approveSettlement} disabled={busy}>{busy ? 'Approving…' : (left > 1 ? 'Approve' : 'Approve & Close Loan')}</Btn>
                      <Btn variant="danger" icon="x" onClick={rejectSettlement} disabled={busy}>Reject</Btn>
                    </div>
                    {req?.firstApprover && <div className="t-2" style={{ fontSize: 11.5 }}>Approved by {req.firstApprover.name} · needs {left} more</div>}
                  </div>
                ) : (
                  <div className="t-2" style={{ fontSize: 12.5 }}>
                    Awaiting approval{req?.firstApprover ? ` — approved by ${req.firstApprover.name}, needs ${left} more` : ''} — the loan will close and the receipt can be printed once approved.
                  </div>
                )
              })()}
            </Card>
          )}

          <div className="row between wrap gap-2" style={{ marginBottom: 16, borderBottom: '1px solid var(--line)', paddingBottom: 4 }}>
            <div className="row gap-2">
              <Btn size="sm" variant="ghost" icon="receipt" active={panelTab === 'history'} onClick={() => setPanelTab('history')}>Payment History</Btn>
              <Btn size="sm" variant="ghost" icon="calendar" active={panelTab === 'schedule'} onClick={() => setPanelTab('schedule')}>Payment Schedule</Btn>
              <Btn size="sm" variant="ghost" icon="doc" active={panelTab === 'details'} onClick={() => setPanelTab('details')}>Loan Details</Btn>
              <Btn size="sm" variant="ghost" icon="doc" active={panelTab === 'ledger'} onClick={() => setPanelTab('ledger')}>Ledger Card</Btn>
            </div>
            <Btn size="sm" variant="solid" icon="plus" onClick={startOver}>New Receipt</Btn>
          </div>

          {panelTab === 'history' && (
            <Card pad={0}>
              <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Payment History</div>
              <Table cols={[{ label: 'Receipt No' }, { label: 'Date' }, { label: 'Method' }, { label: 'Amount', align: 'right' }, { label: 'Received By' }, { label: 'Status', align: 'center' }]}
                rows={paymentHistory} empty="No payments recorded yet for this loan." onRow={(p) => setViewPayment(p)}
                render={(p: LoanPayment) => <>
                  <Td mono style={{ fontWeight: 600 }}>{p.code}</Td>
                  <Td>{dateOnly(p.paidAt)}</Td>
                  <Td>{METHOD_OPTIONS.find((m) => m.value === p.paymentMethod)?.label || p.paymentMethod}{p.paymentType === 'Settlement' ? ' (Settlement)' : ''}</Td>
                  <Td mono align="right">{money(p.amount)}</Td>
                  <Td>{p.receiver?.name || '—'}</Td>
                  <Td align="center"><PaymentStatusBadge p={p} /></Td>
                </>} />
            </Card>
          )}

          {panelTab === 'schedule' && (
            <Card pad={0}>
              <div className="row between wrap gap-2" style={{ padding: '14px 16px 0' }}>
                <div className="eyebrow">Payment Schedule</div>
                <div className="row gap-1">
                  {([['all', 'All'], ['paid', 'Paid'], ['unpaid', 'Not Paid']] as const).map(([id, label]) => (
                    <Btn key={id} size="sm" variant="ghost" active={scheduleTab === id} onClick={() => setScheduleTab(id)}>{label}</Btn>
                  ))}
                </div>
              </div>
              {locked && <div className="t-2" style={{ padding: '8px 16px 0', fontSize: 11.5 }}>Payments are disabled while a settlement request is pending.</div>}
              <Table cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Total Due', align: 'right' }, { label: 'Paid', align: 'right' }, { label: 'Balance', align: 'right' }, { label: 'Status', align: 'center' }]}
                rows={scheduleRows} empty={scheduleTab === 'paid' ? 'No paid installments yet' : scheduleTab === 'unpaid' ? 'No unpaid installments — this loan is fully paid' : 'No installments generated'}
                render={(r: LoanSchedule) => <>
                  <Td>{r.installmentNo}</Td>
                  <Td>{dateOnly(r.dueDate)}</Td>
                  <Td mono align="right">{money(r.totalDue)}</Td>
                  <Td mono align="right">{money(r.paidAmount)}</Td>
                  <Td mono align="right">{money(balanceOf(r))}</Td>
                  <Td align="center"><Badge tone={scheduleTone[r.status]} dot>{r.status}</Badge></Td>
                </>} />
            </Card>
          )}

          {panelTab === 'details' && <LoanDetailsPanel loan={selected} />}
          {panelTab === 'ledger' && <LedgerCard loan={selected} />}
        </>
      )}

      {!selected && !loading && !loadingLoan && (
        <Card pad={0}>
          <div className="row between wrap gap-2" style={{ padding: '14px 16px 0' }}>
            <div className="eyebrow">Loans Awaiting Payment</div>
            {listQ || listFrom || listTo ? <span className="t-2" style={{ fontSize: 12 }}>{filteredPending.length} of {pendingPayment.length}</span> : null}
          </div>
          <div className="row gap-2 wrap" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', alignItems: 'flex-end' }}>
            <div style={{ position: 'relative', maxWidth: 320, flex: 1, minWidth: 200 }}>
              <span className="t-2" style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', display: 'block', marginBottom: 6 }}>Loan-wise Filter</span>
              <span style={{ position: 'absolute', left: 10, top: 32, color: 'var(--tx-2)' }}><Icon n="search" s={15} /></span>
              <Input value={listSearch} onChange={(e) => setListSearch(e.target.value)} placeholder="NIC, loan code, customer, or product…" style={{ paddingLeft: 32 }} />
            </div>
            <Field label="Disbursed From"><Input type="date" value={listFrom} max={listTo || undefined} onChange={(e) => setListFrom(e.target.value)} /></Field>
            <Field label="Disbursed To"><Input type="date" value={listTo} min={listFrom || undefined} onChange={(e) => setListTo(e.target.value)} /></Field>
            {(listSearch || listFrom || listTo) &&
              <Btn variant="ghost" onClick={() => { setListSearch(''); setListFrom(''); setListTo('') }}>Reset</Btn>}
          </div>
          <Table cols={[{ label: 'Loan' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Area' }, { label: 'Disbursed' }, { label: 'Outstanding', align: 'right' }]}
            rows={filteredPending}
            empty={pendingPayment.length ? 'No loans match these filters.' : 'No active loans with an outstanding balance right now.'}
            onRow={(l: Loan) => pickLoan('', l)}
            render={(l: Loan) => <>
              <Td mono style={{ fontWeight: 600 }}>{l.code}</Td>
              <Td>
                <div style={{ fontWeight: 600 }}>{l.customer?.fullName}</div>
                <div className="t-2" style={{ fontSize: 11.5 }}>{l.customer?.nicNo || '—'}</div>
              </Td>
              <Td>{l.loanDetail?.loanType?.name || '—'}</Td>
              <Td>{l.branch?.name || '—'}</Td>
              <Td>{dateOnly(l.disbursedAt)}</Td>
              <Td mono align="right" c="var(--bad)">{money(num(l.totalDueSum) - num(l.paidSum))}</Td>
            </>} />
        </Card>
      )}

      <Modal open={payOpen} onClose={resetPayForm} title="Make Payment"
        sub={selected ? (allocationMode === 'manual' ? `${selected.code} — manual split needs top-level approval before it's applied.` : `${selected.code} — payment is allocated automatically to the oldest unpaid installment first.`) : undefined}
        footer={<>
          <Btn variant="solid" onClick={resetPayForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={record} disabled={busy || (allocationMode === 'manual' && Math.abs(allocTotal - Number(amount || 0)) > 0.01)}>
            {busy ? (allocationMode === 'manual' ? 'Submitting…' : 'Recording…') : (allocationMode === 'manual' ? 'Submit for Approval' : 'Record Payment & Print Receipt')}
          </Btn>
        </>}>
        <div className="col gap-3">
          <ErrorBanner error={err} style={{ marginBottom: 0 }} />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '8px 16px', fontSize: 12.5, paddingBottom: 12, borderBottom: '1px solid var(--line)' }}>
            <div><span className="t-2">Outstanding Balance</span><br /><b style={{ fontFamily: 'JetBrains Mono', color: 'var(--bad)' }}>{money(outstanding)}</b></div>
            <div><span className="t-2">Already Paid</span><br /><b style={{ fontFamily: 'JetBrains Mono' }}>{money(totalPaid)}</b></div>
          </div>
          <Field label="Payment Amount"><Input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={`Up to ${money(outstanding)}`} /></Field>
          <Field label="Allocation">
            <div className="row gap-3" style={{ alignItems: 'center' }}>
              <label className="row gap-1" style={{ alignItems: 'center', cursor: 'pointer' }}>
                <input type="radio" checked={allocationMode === 'auto'} onChange={() => setAllocationMode('auto')} /> <span style={{ fontSize: 12.5 }}>Auto (oldest unpaid first)</span>
              </label>
              <label className="row gap-1" style={{ alignItems: 'center', cursor: 'pointer' }}>
                <input type="radio" checked={allocationMode === 'manual'} onChange={() => setAllocationMode('manual')} /> <span style={{ fontSize: 12.5 }}>Manual split — needs approval</span>
              </label>
            </div>
          </Field>
          {allocationMode === 'manual' && (() => {
            const target = allocTarget
            const balanced = Math.abs(allocTotal - target) <= 0.01
            return (<>
              <div className="row gap-3" style={{ alignItems: 'center', marginBottom: 8 }}>
                <span className="t-2" style={{ fontSize: 11.5 }}>Enter as:</span>
                <label className="row gap-1" style={{ alignItems: 'center', cursor: 'pointer' }}>
                  <input type="radio" checked={!allocByPercent} onChange={() => toggleAllocUnit(false)} /> <span style={{ fontSize: 12.5 }}>Amount</span>
                </label>
                <label className="row gap-1" style={{ alignItems: 'center', cursor: 'pointer' }}>
                  <input type="radio" checked={allocByPercent} onChange={() => toggleAllocUnit(true)} /> <span style={{ fontSize: 12.5 }}>Percentage (%)</span>
                </label>
              </div>
              <div style={{ border: '1px solid var(--line)', borderRadius: 'var(--r-s)', overflow: 'hidden' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                  <thead>
                    <tr>
                      <th style={{ textAlign: 'left', padding: '9px 12px', fontSize: 11, fontWeight: 600, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.06em', borderBottom: '1px solid var(--line)', background: 'var(--bg-2)' }}>Bucket</th>
                      <th style={{ textAlign: 'right', padding: '9px 12px', fontSize: 11, fontWeight: 600, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.06em', borderBottom: '1px solid var(--line)', background: 'var(--bg-2)' }}>Due</th>
                      <th style={{ textAlign: 'right', padding: '9px 12px', fontSize: 11, fontWeight: 600, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.06em', borderBottom: '1px solid var(--line)', background: 'var(--bg-2)', width: 130 }}>{allocByPercent ? '%' : 'Amount'}</th>
                      {allocByPercent && <th style={{ textAlign: 'right', padding: '9px 12px', fontSize: 11, fontWeight: 600, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.06em', borderBottom: '1px solid var(--line)', background: 'var(--bg-2)', width: 130 }}>Amount</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {allocRows.map((row) => {
                      const computedAmt = pctToAmt(row.value)
                      const over = allocDue !== null && computedAmt > row.due
                      return (
                        <tr key={row.key}>
                          <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--line-soft)' }}>{row.label}</td>
                          <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--line-soft)', textAlign: 'right', fontFamily: 'JetBrains Mono', color: 'var(--tx-2)' }}>{allocDue ? money(row.due) : '…'}</td>
                          <td style={{ padding: '6px 8px', borderBottom: '1px solid var(--line-soft)' }}>
                            <Input type="number" value={row.value} onChange={(e) => row.set(e.target.value)}
                              style={{ textAlign: 'right', ...(over ? { color: 'var(--bad)', borderColor: 'var(--bad)' } : {}) }} />
                          </td>
                          {allocByPercent && (
                            <td style={{ padding: '8px 12px', borderBottom: '1px solid var(--line-soft)', textAlign: 'right', fontFamily: 'JetBrains Mono', color: over ? 'var(--bad)' : 'var(--tx-2)' }}>
                              {money(computedAmt)}
                            </td>
                          )}
                        </tr>
                      )
                    })}
                  </tbody>
                  <tfoot>
                    <tr>
                      <td colSpan={allocByPercent ? 3 : 2} style={{ padding: '9px 12px', fontWeight: 600, background: 'var(--bg-2)' }}>Split Total</td>
                      <td style={{ padding: '9px 12px', textAlign: 'right', fontFamily: 'JetBrains Mono', fontWeight: 700, background: 'var(--bg-2)', color: balanced ? 'var(--ok)' : 'var(--bad)' }}>
                        {money(allocTotal)} / {money(target)}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </>)
          })()}
          <PaymentFields method={method} setMethod={setMethod} paidAt={paidAt} setPaidAt={setPaidAt} backDated={payBackDated} setBackDated={setPayBackDated} refNo={refNo} setRefNo={setRefNo} nextReceipt={nextReceipt}
            bankId={payBankId} setBankId={setPayBankId} chequeDate={payChequeDate} setChequeDate={setPayChequeDate} notes={notes} setNotes={setNotes} />
          {canCollectElsewhere && method === 'Cash' && (
            <Field label="Collected at branch">
              <Select id="collected-branch" value={collectedBranchId ?? ''} onChange={(e) => setCollectedBranchId(e.target.value ? Number(e.target.value) : undefined)}>
                <option value="">The loan's own branch</option>
                {allBranches.filter((b) => b.id !== selected?.branchId).map((b) => <option key={b.id} value={b.id}>{b.code} — {b.name}</option>)}
              </Select>
              <div className="t-2" style={{ fontSize: 12, marginTop: 4 }}>Pick another branch if the cash was taken at its counter. Its till is credited and the two branches settle through the inter-branch accounts.</div>
            </Field>
          )}
        </div>
      </Modal>

      <Modal open={settleOpen} onClose={resetSettleForm} title="Settle Loan Completely" sub={selected?.code}
        footer={<>
          <Btn variant="solid" onClick={resetSettleForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={submitSettlement} disabled={settleBusy}>{settleBusy ? 'Submitting…' : 'Submit for Approval'}</Btn>
        </>}>
        <div className="col gap-3">
          <ErrorBanner error={settleErr} style={{ marginBottom: 0 }} />
          <div className="t-2" style={{ fontSize: 12.5 }}>This pays off the full outstanding balance of <b style={{ fontFamily: 'JetBrains Mono' }}>{money(outstanding)}</b> and must be approved by a Branch Manager before the loan closes and the receipt can be printed.</div>
          <PaymentFields method={settleMethod} setMethod={setSettleMethod} paidAt={settlePaidAt} setPaidAt={setSettlePaidAt} backDated={settleBackDated} setBackDated={setSettleBackDated} refNo={settleRefNo} setRefNo={setSettleRefNo} nextReceipt={nextReceipt}
            bankId={settleBankId} setBankId={setSettleBankId} chequeDate={settleChequeDate} setChequeDate={setSettleChequeDate} notes={settleNotes} setNotes={setSettleNotes} />
        </div>
      </Modal>

      <Modal open={chequeOpen} onClose={resetChequeForm} title="Record Cheque" sub={selected?.code}
        footer={<>
          <Btn variant="solid" onClick={resetChequeForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={submitCheque} disabled={chequeBusy}>{chequeBusy ? 'Recording…' : 'Record Cheque'}</Btn>
        </>}>
        <div className="col gap-3">
          <ErrorBanner error={chequeErr} style={{ marginBottom: 0 }} />
          <div className="t-2" style={{ fontSize: 12.5 }}>This is a record only — it won't reduce the outstanding balance until the cheque is marked Realized on the loan's Post Dated Cheques tab.</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16 }}>
            <Field label="Cheque No"><Input value={chequeNo} onChange={(e) => setChequeNo(e.target.value)} /></Field>
            <Field label="Bank"><BankSelect value={rcBankId} onChange={setRcBankId} /></Field>
            <Field label="Cheque Date"><Input type="date" value={rcChequeDate} onChange={(e) => setRcChequeDate(e.target.value)} /></Field>
            <Field label="Amount"><Input type="number" value={chequeAmount} onChange={(e) => setChequeAmount(e.target.value)} /></Field>
          </div>
        </div>
      </Modal>

      <Modal open={!!viewPayment} onClose={() => setViewPayment(null)} title={viewPayment ? `Payment ${viewPayment.code}` : ''} sub={selected ? `${selected.code} — ${selected.customer?.fullName}` : undefined}
        footer={<>
          {viewPayment && !viewPayment.pendingVoidRequest && viewPayment.status !== 'Voided' && viewPayment.approvalStatus !== 'PendingApproval' && (
            <Can user={me} module="Loan Payments" action="Delete">
              <Btn variant="danger" icon="x" onClick={() => voidPayment(viewPayment)}>Cancel Receipt</Btn>
            </Can>
          )}
          {viewPayment?.pendingVoidRequest && canApprove(me, 'payment_void')
            && me?.id !== viewPayment.pendingVoidRequest.requester?.id && me?.id !== viewPayment.pendingVoidRequest.firstApprover?.id && (
            <>
              <Btn variant="danger" icon="x" onClick={() => rejectVoid(viewPayment)}>Reject</Btn>
              <Btn variant="primary" icon="check" onClick={() => approveVoid(viewPayment)}>Approve</Btn>
            </>
          )}
          {viewPayment?.pendingAllocationRequest && canApprove(me, 'payment_manual_allocation')
            && me?.id !== viewPayment.pendingAllocationRequest.requester?.id && me?.id !== viewPayment.pendingAllocationRequest.firstApprover?.id && (
            <>
              <Btn variant="danger" icon="x" onClick={() => rejectAllocation(viewPayment)}>Reject</Btn>
              <Btn variant="primary" icon="check" onClick={() => approveAllocation(viewPayment)}>Approve</Btn>
            </>
          )}
          <Btn variant="solid" onClick={() => setViewPayment(null)}>Close</Btn>
        </>}>
        {viewPayment && (() => {
          const p = viewPayment
          const pendingClearance = p.paymentMethod === 'Cheque' && p.clearanceStatus === 'Pending'
          return (
            <div className="col gap-2" style={{ fontSize: 13 }}>
              <div className="row between"><span className="t-2">Date</span><b>{dateOnly(p.paidAt)}</b></div>
              <div className="row between"><span className="t-2">Status</span><PaymentStatusBadge p={p} /></div>
              <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}>
                <span className="t-2">Amount</span><span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(p.amount)}</span>
              </div>
              <div className="row between"><span className="t-2">Payment Method</span><b>{METHOD_OPTIONS.find((m) => m.value === p.paymentMethod)?.label || p.paymentMethod}{p.paymentType === 'Settlement' ? ' (Settlement)' : ''}</b></div>
              {p.receiptNo && <div className="row between"><span className="t-2">{REF_LABEL[p.paymentMethod]}</span><b>{p.receiptNo}</b></div>}
              {p.paymentMethod === 'Cheque' && p.bank?.name && <div className="row between"><span className="t-2">Bank</span><b>{p.bank.name}</b></div>}
              {p.paymentMethod === 'Cheque' && p.chequeDate && <div className="row between"><span className="t-2">Cheque Date</span><b>{dateOnly(p.chequeDate)}</b></div>}
              {p.paymentMethod === 'Cheque' && p.clearanceStatus && <div className="row between"><span className="t-2">Clearance</span><b>{p.clearanceStatus}</b></div>}
              {p.clearanceStatus === 'Realized' && p.realizedAt && <div className="row between"><span className="t-2">Realized On</span><b>{dateOnly(p.realizedAt)}</b></div>}
              {p.notes && <div className="row between"><span className="t-2">Notes</span><b>{p.notes}</b></div>}
              <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}><span className="t-2">Received By</span><b>{p.receiver?.name || '—'}</b></div>
              {p.paymentType === 'Settlement' && <div className="row between"><span className="t-2">Approval Status</span><b>{p.approvalStatus || '—'}</b></div>}
              {p.paymentType === 'Settlement' && p.approver?.name && <div className="row between"><span className="t-2">Approved By</span><b>{p.approver.name}{p.approvedAt ? ` · ${dateOnly(p.approvedAt)}` : ''}</b></div>}
              {p.status === 'Voided' && (
                <div className="col gap-1" style={{ marginTop: 8, padding: '8px 12px', background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
                  <div>Voided {dateOnly(p.voidedAt)} by {p.voider?.name || '—'}</div>
                  {p.voidReason && <div>Reason: {p.voidReason}</div>}
                </div>
              )}
              {p.pendingVoidRequest && (
                <div className="col gap-1" style={{ marginTop: 8, padding: '8px 12px', background: 'var(--warn-dim)', color: 'var(--warn)', border: '1px solid var(--warn)', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
                  <div><b>Cancel request pending</b> — {p.pendingVoidRequest.requester?.name || '—'}{p.pendingVoidRequest.reason ? ` · ${p.pendingVoidRequest.reason}` : ''}</div>
                  <div>
                    {p.pendingVoidRequest.firstApprover ? `Approved by ${p.pendingVoidRequest.firstApprover.name}, ` : ''}
                    needs {p.pendingVoidRequest.requiredApprovals - (p.pendingVoidRequest.firstApprover ? 1 : 0)} more approval{p.pendingVoidRequest.requiredApprovals - (p.pendingVoidRequest.firstApprover ? 1 : 0) === 1 ? '' : 's'}
                  </div>
                </div>
              )}
              {p.paymentType === 'ManualAllocation' && p.manualAllocation && (
                <div className="col gap-1" style={{ marginTop: 8, padding: '8px 12px', background: 'var(--warn-dim)', color: 'var(--warn)', border: '1px solid var(--warn)', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
                  <div><b>Requested split</b> — Interest {money(p.manualAllocation.interest)} · Capital {money(p.manualAllocation.capital)} · OD {money(p.manualAllocation.odCharge)} · Other {money(p.manualAllocation.otherCharges)}</div>
                  {p.pendingAllocationRequest && (
                    <div>
                      {p.pendingAllocationRequest.requester?.name ? `Requested by ${p.pendingAllocationRequest.requester.name}. ` : ''}
                      {p.pendingAllocationRequest.firstApprover ? `Approved by ${p.pendingAllocationRequest.firstApprover.name}, ` : ''}
                      needs {p.pendingAllocationRequest.requiredApprovals - (p.pendingAllocationRequest.firstApprover ? 1 : 0)} more approval{p.pendingAllocationRequest.requiredApprovals - (p.pendingAllocationRequest.firstApprover ? 1 : 0) === 1 ? '' : 's'}
                    </div>
                  )}
                </div>
              )}
              {pendingClearance && (
                <div style={{ marginTop: 8, background: 'var(--warn-dim)', color: 'var(--warn)', border: '1px solid var(--warn)', padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
                  This cheque is held pending clearance and has not been applied to the balance.
                </div>
              )}
            </div>
          )
        })()}
      </Modal>
    </div>
  )
}

/* ---------- Speed Draft Payment Receipt ----------
   Look up any active Speed Draft, then record one of three distinct actions —
   Pay Interest, Make Capital Payment, or Settle Full Balance — each posted through
   speedDraftsApi/{id}/payments with a payment_type so interest and capital
   reductions stay separately trackable in history (see SpeedDraftPaymentAllocator).
   Kept as its own component (rather than folded into PaymentReceiptScreen's flow)
   because the underlying workflow genuinely differs from loans — no cheque
   clearance, no settlement approval step, three payment types instead of one —
   but lives in this file so the two screens share the receipt-shell layout and
   search-box pattern instead of duplicating that boilerplate. */
const SD_METHOD_OPTIONS = METHOD_OPTIONS
const SD_PAYMENT_TYPE_LABEL: Record<SpeedDraftPaymentType, string> = { Interest: 'Interest', CapitalReduction: 'Capital Reduction', Settlement: 'Full Settlement' }
const sdScheduleTone: Record<string, 'green' | 'amber' | 'neutral'> = { Paid: 'green', Partial: 'amber', Pending: 'neutral' }
const SD_CATEGORY_LABEL: Record<string, string> = { Loan: 'Loan', Mortgage: 'Mortgage', GoldPawning: 'Gold Pawning', Lease: 'Lease' }

function SpeedDraftPaymentStatusBadge({ p }: { p: SpeedDraftPayment }) {
  if (p.status === 'Voided') return <Badge tone="red" dot>Voided</Badge>
  return <Badge tone="green" dot>Recorded</Badge>
}

/** Read-only "everything about this draft" tab — mirrors LoanDetailsPanel, scoped
 *  to the fields speedDraftsApi.get() actually loads (see SpeedDraftController::DETAIL_RELATIONS). */
function SpeedDraftDetailsPanel({ draft: d }: { draft: SpeedDraft }) {
  const type = d.speedDraftDetail?.speedDraftType
  const collateral = d.collateral || []
  return (
    <div className="col gap-3">
      <Card>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Customer Information</div>
        <div style={infoGridStyle}>
          <div><span className="t-2">Full Name: </span><b>{d.customer?.fullName || '—'}</b></div>
          <div><span className="t-2">Customer Code: </span><b>{d.customer?.code || '—'}</b></div>
          <div><span className="t-2">NIC No: </span><b>{d.customer?.nicNo || '—'}</b></div>
          <div><span className="t-2">Phone: </span><b>{d.customer?.phone || '—'}</b></div>
          <div><span className="t-2">Branch: </span><b>{d.branch?.name || '—'}</b></div>
        </div>
      </Card>

      <Card>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Speed Draft / Facility Details</div>
        <div style={infoGridStyle}>
          <div><span className="t-2">Draft Code: </span><b>{d.code}</b></div>
          <div><span className="t-2">Status: </span><Badge tone="green" dot>{d.status}</Badge></div>
          <div><span className="t-2">Product: </span><b>{type?.name || '—'}{type?.category ? ` (${SD_CATEGORY_LABEL[type.category] || type.category})` : ''}</b></div>
          <div><span className="t-2">Principal Amount: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(d.principalAmount)}</b></div>
          <div><span className="t-2">Term: </span><b>{d.termMonths} months</b></div>
          <div><span className="t-2">Interest Rate: </span><b>{Number(d.interestRatePercent).toFixed(3)}% {d.ratePeriod === 'annual' ? 'p.a.' : 'p.m.'}</b></div>
          <div><span className="t-2">Start Date: </span><b>{dateOnly(d.startDate)}</b></div>
          <div><span className="t-2">Maturity Date: </span><b>{dateOnly(d.maturityDate)}</b></div>
          <div><span className="t-2">Disbursed At: </span><b>{dateOnly(d.disbursedAt)}</b></div>
          <div><span className="t-2">Disbursed Amount: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{d.disbursedAmount != null ? money(d.disbursedAmount) : '—'}</b></div>
          {d.speedDraftDetail?.vehicleNo && <div><span className="t-2">Vehicle No: </span><b>{d.speedDraftDetail.vehicleNo}</b></div>}
          <div><span className="t-2">Approved By: </span><b>{d.approver?.name || '—'}</b></div>
        </div>
      </Card>

      {!!d.insurance && (
        <Card>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Insurance Policy</div>
          <div style={infoGridStyle}>
            <div><span className="t-2">Company: </span><b>{d.insurance.insuranceCompany || '—'}</b></div>
            <div><span className="t-2">Policy No: </span><b>{d.insurance.policyNo || '—'}</b></div>
            <div><span className="t-2">Amount: </span><b>{d.insurance.amount != null ? money(d.insurance.amount) : '—'}</b></div>
            <div><span className="t-2">Contact Person: </span><b>{d.insurance.contactPerson || '—'}</b></div>
            <div><span className="t-2">Coverage: </span><b>{d.insurance.coverage || '—'}</b></div>
            {d.insurance.remarks && <div style={{ gridColumn: '1/-1' }}><span className="t-2">Remark: </span><b>{d.insurance.remarks}</b></div>}
          </div>
        </Card>
      )}

      {!!collateral.length && (
        <Card pad={0}>
          <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Pledged Collateral</div>
          <Table cols={[{ label: 'Type' }, { label: 'Description' }, { label: 'Weight (g)', align: 'right' }, { label: 'Purity' }, { label: 'Appraised Value', align: 'right' }]}
            rows={collateral} empty="No collateral recorded"
            render={(c) => <>
              <Td>{c.collateralType}</Td>
              <Td>{c.description}</Td>
              <Td mono align="right">{c.jewelryDetail?.weightGrams != null ? Number(c.jewelryDetail.weightGrams).toFixed(3) : '—'}</Td>
              <Td>{c.jewelryDetail?.purity || '—'}</Td>
              <Td mono align="right">{c.estimatedValue != null ? money(c.estimatedValue) : '—'}</Td>
            </>} />
        </Card>
      )}

      {d.rejectedReason && (
        <Card>
          <div style={{ background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', padding: '10px 14px', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
            Rejected: {d.rejectedReason}
          </div>
        </Card>
      )}
    </div>
  )
}

/* ---------- Ledger Card (computed, client-side) — mirrors LoanDetail's LedgerCard,
   adapted to Speed Draft's interest-only schedule (no waivers/charges concept). ---------- */
function SpeedDraftLedgerCard({ draft: d }: { draft: SpeedDraft }) {
  type Row = { date: string; particulars: string; debit: number; credit: number; typeOrder: number }
  const rows: Row[] = []

  for (const s of d.schedules || []) rows.push({ date: s.dueDate, particulars: `Installment #${s.installmentNo} interest due`, debit: num(s.interestDue), credit: 0, typeOrder: 1 })
  for (const p of d.payments || []) {
    if (p.status === 'Voided') continue
    rows.push({ date: p.paidAt, particulars: `Receipt ${p.code} — ${SD_PAYMENT_TYPE_LABEL[p.paymentType] || p.paymentType} (${p.paymentMethod})`, debit: 0, credit: num(p.amount), typeOrder: 2 })
  }

  rows.sort((a, b) => {
    const dCmp = a.date.substring(0, 10).localeCompare(b.date.substring(0, 10))
    if (dCmp !== 0) return dCmp
    return a.typeOrder - b.typeOrder
  })
  let balance = 0
  const withBalance = rows.map((r) => { balance += r.debit - r.credit; return { ...r, balance } })

  return (
    <Card pad={0}>
      <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Ledger Card</div>
      <Table cols={[{ label: 'Date' }, { label: 'Particulars' }, { label: 'Debit', align: 'right' }, { label: 'Credit', align: 'right' }, { label: 'Balance', align: 'right' }]}
        rows={withBalance} empty="No ledger entries yet"
        render={(r) => <>
          <Td>{dateOnly(r.date)}</Td>
          <Td>{r.particulars}</Td>
          <Td mono align="right">{r.debit ? money(r.debit) : '—'}</Td>
          <Td mono align="right">{r.credit ? money(r.credit) : '—'}</Td>
          <Td mono align="right">{money(r.balance)}</Td>
        </>} />
    </Card>
  )
}

export function SpeedDraftPaymentReceiptScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const [drafts, setDrafts] = useState<SpeedDraft[]>([])
  const [loading, setLoading] = useState(true)
  const [brand, setBrand] = useState({ name: '', tagline: '' })

  const [selected, setSelected] = useState<SpeedDraft | null>(null)
  const [loadingDraft, setLoadingDraft] = useState(false)

  // Pay Interest / Make Capital Payment share one modal, distinguished by `payType`.
  const [payOpen, setPayOpen] = useState(false)
  const [payType, setPayType] = useState<SpeedDraftPaymentType>('Interest')
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<PaymentMethod>('Cash')
  const [paidAt, setPaidAt] = useState(todayIso())
  const [payBackDated, setPayBackDated] = useState(false)
  const [receiptNo, setReceiptNo] = useState('')
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [settleOpen, setSettleOpen] = useState(false)
  const [settleMethod, setSettleMethod] = useState<PaymentMethod>('Cash')
  const [settlePaidAt, setSettlePaidAt] = useState(todayIso())
  const [settleBackDated, setSettleBackDated] = useState(false)
  const [settleReceiptNo, setSettleReceiptNo] = useState('')
  const [settleNotes, setSettleNotes] = useState('')
  const [settleBusy, setSettleBusy] = useState(false)
  const [settleErr, setSettleErr] = useState('')

  const [nextReceipt, setNextReceipt] = useState('')
  const [panelTab, setPanelTab] = useState<'history' | 'schedule' | 'details' | 'ledger'>('history')
  const [scheduleTab, setScheduleTab] = useState<'all' | 'paid' | 'unpaid'>('unpaid')
  const [viewPayment, setViewPayment] = useState<SpeedDraftPayment | null>(null)
  const [receipt, setReceipt] = useState<{ payment: SpeedDraftPayment; draft: SpeedDraft } | null>(null)

  const refresh = () => {
    setLoading(true)
    speedDraftsApi.list({ status: 'Active' }).then(setDrafts).catch(() => setDrafts([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
  }, [])

  useEffect(() => {
    const activeType = payOpen ? payType : settleOpen ? 'Settlement' : null
    if (!selected || !activeType) { setNextReceipt(''); return }
    let cancelled = false
    speedDraftPaymentsApi.nextReceiptNo(selected.id, activeType).then((code) => { if (!cancelled) setNextReceipt(code) }).catch(() => { if (!cancelled) setNextReceipt('') })
    return () => { cancelled = true }
  }, [selected, payOpen, payType, settleOpen])

  const summary = selected?.summary
  const schedules = (selected?.schedules || []).slice().sort((a, b) => a.installmentNo - b.installmentNo)
  const paymentHistory = (selected?.payments || []).slice().sort((a, b) => new Date(b.paidAt).getTime() - new Date(a.paidAt).getTime())
  const scheduleRows = scheduleTab === 'paid' ? schedules.filter((s) => s.status === 'Paid')
    : scheduleTab === 'unpaid' ? schedules.filter((s) => s.status !== 'Paid')
    : schedules

  const resetPayForm = () => { setPayOpen(false); setAmount(''); setMethod('Cash'); setPaidAt(todayIso()); setPayBackDated(false); setReceiptNo(''); setNotes(''); setErr('') }
  const resetSettleForm = () => { setSettleOpen(false); setSettleMethod('Cash'); setSettlePaidAt(todayIso()); setSettleBackDated(false); setSettleReceiptNo(''); setSettleNotes(''); setSettleErr('') }

  const pickDraft = async (_n: string, opt?: SpeedDraft) => {
    resetPayForm(); resetSettleForm(); setScheduleTab('unpaid'); setPanelTab('history')
    if (!opt) { setSelected(null); return }
    setLoadingDraft(true)
    try { setSelected(await speedDraftsApi.get(opt.id)) }
    catch { setSelected(opt) }
    finally { setLoadingDraft(false) }
  }

  const openPay = (type: SpeedDraftPaymentType) => { setPayType(type); setPayOpen(true); setAmount(''); setMethod('Cash'); setPaidAt(todayIso()); setPayBackDated(false); setReceiptNo(''); setNotes(''); setErr('') }

  const record = async () => {
    if (!selected) return
    if (!amount || Number(amount) <= 0) { setErr('Enter a payment amount.'); return }
    setBusy(true); setErr('')
    try {
      const payment = await speedDraftPaymentsApi.create(selected.id, {
        amount, paymentType: payType, paymentMethod: method, paidAt, receiptNo: receiptNo || undefined, notes: notes || undefined,
      })
      const fresh = await speedDraftsApi.get(selected.id)
      setReceipt({ payment, draft: fresh })
      refresh()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Payment failed'))
    } finally { setBusy(false) }
  }

  const submitSettlement = async () => {
    if (!selected) return
    setSettleBusy(true); setSettleErr('')
    try {
      const payment = await speedDraftPaymentsApi.create(selected.id, {
        amount: summary?.amountToSettleToday || 0, paymentType: 'Settlement', paymentMethod: settleMethod,
        paidAt: settlePaidAt, receiptNo: settleReceiptNo || undefined, notes: settleNotes || undefined,
      })
      const fresh = await speedDraftsApi.get(selected.id)
      setReceipt({ payment, draft: fresh })
      refresh()
    } catch (ex: any) {
      setSettleErr(getErrorText(ex, 'Settlement failed'))
    } finally { setSettleBusy(false) }
  }

  const startOver = () => { setReceipt(null); setSelected(null); resetPayForm(); resetSettleForm() }

  if (receipt) {
    const { payment, draft } = receipt
    return (
      <div>
        <div className="row between wrap gap-2 no-print" style={{ marginBottom: 16 }}>
          <PageHead crumbs="Speed Drafts" title="Payment Receipt" icon="receipt"
            sub={payment.paymentType === 'Settlement' ? 'Settlement recorded — draft closed. Print or issue the receipt below.' : 'Payment recorded — print or issue the receipt below.'} />
        </div>
        <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
          <Btn variant="primary" icon="print" onClick={() => window.print()}>Print Receipt</Btn>
          <Btn variant="solid" icon="plus" onClick={startOver}>New Payment</Btn>
        </div>

        <Card style={{ maxWidth: 560, margin: '0 auto', fontFamily: 'Outfit' }}>
          <div className="col gap-1" style={{ textAlign: 'center', marginBottom: 18, paddingBottom: 18, borderBottom: '1px dashed var(--line)' }}>
            <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 20 }}>{brand.name || 'Company'}</div>
            {brand.tagline && <div className="t-2" style={{ fontSize: 12 }}>{brand.tagline}</div>}
            <div style={{ fontWeight: 700, fontSize: 14, letterSpacing: '.08em', marginTop: 8 }}>{SD_PAYMENT_TYPE_LABEL[payment.paymentType].toUpperCase()} RECEIPT</div>
          </div>

          <div className="row between" style={{ marginBottom: 14, fontSize: 13 }}>
            <div><span className="t-2">Receipt No: </span><b>{payment.code}</b></div>
            <div><span className="t-2">Date: </span><b>{dateOnly(payment.paidAt)}</b></div>
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Received From</span><b>{draft.customer?.fullName}</b></div>
            <div className="row between"><span className="t-2">NIC No</span><b>{draft.customer?.nicNo || '—'}</b></div>
            <div className="row between"><span className="t-2">Draft No</span><b>{draft.code}</b></div>
            <div className="row between"><span className="t-2">Product</span><b>{draft.speedDraftDetail?.speedDraftType?.name || '—'}</b></div>
            {payment.paymentType === 'Settlement' && <div className="row between"><span className="t-2">Draft Status</span><b>Closed</b></div>}
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Payment Type</span><b>{SD_PAYMENT_TYPE_LABEL[payment.paymentType]}</b></div>
            <div className="row between"><span className="t-2">Payment Method</span><b>{SD_METHOD_OPTIONS.find((m) => m.value === payment.paymentMethod)?.label || payment.paymentMethod}</b></div>
            {payment.receiptNo && <div className="row between"><span className="t-2">Reference No</span><b>{payment.receiptNo}</b></div>}
            {payment.notes && <div className="row between"><span className="t-2">Notes</span><b>{payment.notes}</b></div>}
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 20 }}>
            <div className="row between"><span className="t-2">Amount Paid</span><span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(payment.amount)}</span></div>
            <div className="row between" style={{ fontSize: 15, fontWeight: 700, paddingTop: 6, borderTop: '1px solid var(--line)' }}>
              <span>Outstanding Capital</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(draft.summary?.outstandingCapital ?? draft.outstandingBalanceCache)}</span>
            </div>
          </div>

          <div className="row between" style={{ fontSize: 12, marginTop: 30 }}>
            <div>Received By: <b>{payment.receiver?.name || me?.name || '—'}</b></div>
            <div>Signature: ______________</div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Speed Drafts" title="Payment Receipt" icon="receipt"
        sub="Look up an active Speed Draft, then pay interest, make a capital payment, or settle the full balance." />

      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Find Speed Draft</div>
        <SearchSelect
          value={selected ? `${selected.code} — ${selected.customer?.fullName}` : ''}
          onChange={(n, opt) => pickDraft(n, opt)}
          options={drafts}
          getLabel={(d: SpeedDraft) => `${d.code} — ${d.customer?.fullName}`}
          getSub={(d: SpeedDraft) => [d.customer?.nicNo, d.customer?.phone].filter(Boolean).join(' · ')}
          placeholder={loading ? 'Loading Speed Drafts…' : 'Search by NIC, Draft No, or customer name…'}
          disabled={loading}
        />
      </Card>

      {loadingDraft && <div style={{ padding: 30, textAlign: 'center', color: 'var(--tx-2)' }}>Loading Speed Draft…</div>}

      {selected && !loadingDraft && summary && (
        <>
          <Card style={{ marginBottom: 16 }}>
            <div className="row between wrap gap-2" style={{ marginBottom: 10 }}>
              <div className="eyebrow">Speed Draft Summary</div>
              <Can user={me} module="Speed Draft Payments" action="Create">
                <div className="row gap-2">
                  {summary.outstandingInterest > 0 && <Btn variant="primary" size="sm" icon="check" onClick={() => openPay('Interest')}>Pay Interest</Btn>}
                  {summary.outstandingCapital > 0 && summary.outstandingInterest <= 0 && <Btn variant="solid" size="sm" icon="coins" onClick={() => openPay('CapitalReduction')}>Make Capital Payment</Btn>}
                  {summary.amountToSettleToday > 0 && <Btn variant="solid" size="sm" icon="flag" onClick={() => setSettleOpen(true)}>Settle Full Balance</Btn>}
                </div>
              </Can>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5 }}>
              <div><span className="t-2">Customer: </span><b>{selected.customer?.fullName}</b></div>
              <div><span className="t-2">NIC: </span><b>{selected.customer?.nicNo || '—'}</b></div>
              <div><span className="t-2">Draft Code: </span><b>{selected.code}</b></div>
              <div><span className="t-2">Product: </span><b>{selected.speedDraftDetail?.speedDraftType?.name || '—'}</b></div>
              <div><span className="t-2">Outstanding Capital: </span><b style={{ fontFamily: 'JetBrains Mono', color: summary.outstandingCapital > 0 ? 'var(--bad)' : 'var(--ok)' }}>{money(summary.outstandingCapital)}</b></div>
              <div><span className="t-2">Outstanding Interest: </span><b style={{ fontFamily: 'JetBrains Mono', color: summary.outstandingInterest > 0 ? 'var(--bad)' : 'var(--ok)' }}>{money(summary.outstandingInterest)}</b></div>
              <div><span className="t-2">Next Due: </span><b>{summary.nextDueDate ? `${dateOnly(summary.nextDueDate)} — ${money(summary.nextDueAmount)}` : '—'}</b></div>
              <div><span className="t-2">Amount to Settle Today: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(summary.amountToSettleToday)}</b></div>
            </div>
          </Card>

          <div className="row gap-2" style={{ marginBottom: 16, borderBottom: '1px solid var(--line)', paddingBottom: 4 }}>
            <Btn size="sm" variant="ghost" icon="receipt" active={panelTab === 'history'} onClick={() => setPanelTab('history')}>Payment History</Btn>
            <Btn size="sm" variant="ghost" icon="calendar" active={panelTab === 'schedule'} onClick={() => setPanelTab('schedule')}>Interest Schedule</Btn>
            <Btn size="sm" variant="ghost" icon="doc" active={panelTab === 'details'} onClick={() => setPanelTab('details')}>Draft Details</Btn>
            <Btn size="sm" variant="ghost" icon="doc" active={panelTab === 'ledger'} onClick={() => setPanelTab('ledger')}>Ledger Card</Btn>
          </div>

          {panelTab === 'history' && (
            <Card pad={0}>
              <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Payment History</div>
              <Table cols={[{ label: 'Receipt No' }, { label: 'Date' }, { label: 'Type' }, { label: 'Method' }, { label: 'Amount', align: 'right' }, { label: 'Received By' }, { label: 'Status', align: 'center' }]}
                rows={paymentHistory} empty="No payments recorded yet for this Speed Draft." onRow={(p) => setViewPayment(p)}
                render={(p: SpeedDraftPayment) => <>
                  <Td mono style={{ fontWeight: 600 }}>{p.code}</Td>
                  <Td>{dateOnly(p.paidAt)}</Td>
                  <Td>{SD_PAYMENT_TYPE_LABEL[p.paymentType] || p.paymentType}</Td>
                  <Td>{SD_METHOD_OPTIONS.find((m) => m.value === p.paymentMethod)?.label || p.paymentMethod}</Td>
                  <Td mono align="right">{money(p.amount)}</Td>
                  <Td>{p.receiver?.name || '—'}</Td>
                  <Td align="center"><SpeedDraftPaymentStatusBadge p={p} /></Td>
                </>} />
            </Card>
          )}

          {panelTab === 'schedule' && (
            <Card pad={0}>
              <div className="row between wrap gap-2" style={{ padding: '14px 16px 0' }}>
                <div className="eyebrow">Interest Schedule</div>
                <div className="row gap-1">
                  {([['all', 'All'], ['paid', 'Paid'], ['unpaid', 'Not Paid']] as const).map(([id, label]) => (
                    <Btn key={id} size="sm" variant="ghost" active={scheduleTab === id} onClick={() => setScheduleTab(id)}>{label}</Btn>
                  ))}
                </div>
              </div>
              <Table cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Interest Due', align: 'right' }, { label: 'Capital Due', align: 'right' }, { label: 'Paid', align: 'right' }, { label: 'Status', align: 'center' }]}
                rows={scheduleRows} empty={scheduleTab === 'paid' ? 'No paid installments yet' : scheduleTab === 'unpaid' ? 'No unpaid installments — interest is fully paid' : 'No installments generated'}
                render={(r: SpeedDraftSchedule) => <>
                  <Td>{r.installmentNo}</Td>
                  <Td>{dateOnly(r.dueDate)}</Td>
                  <Td mono align="right">{money(r.interestDue)}</Td>
                  <Td mono align="right">{num(r.principalDue) > 0 ? money(r.principalDue) : '—'}</Td>
                  <Td mono align="right">{money(r.paidAmount)}</Td>
                  <Td align="center"><Badge tone={sdScheduleTone[r.status]} dot>{r.status}</Badge></Td>
                </>} />
            </Card>
          )}

          {panelTab === 'details' && <SpeedDraftDetailsPanel draft={selected} />}
          {panelTab === 'ledger' && <SpeedDraftLedgerCard draft={selected} />}
        </>
      )}

      {!selected && !loading && !loadingDraft && (
        <div className="t-2" style={{ fontSize: 12.5, textAlign: 'center', padding: '20px 0' }}>
          <Icon n="search" s={16} /> Search above to find an active Speed Draft by NIC number, draft code, or customer name.
        </div>
      )}

      <Modal open={payOpen} onClose={resetPayForm} title={payType === 'Interest' ? 'Pay Interest' : 'Make Capital Payment'}
        sub={selected ? (payType === 'Interest' ? `${selected.code} — applied to the oldest unpaid interest installment(s) first.` : `${selected.code} — reduces outstanding capital and re-prices future interest.`) : undefined}
        footer={<>
          <Btn variant="solid" onClick={resetPayForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={record} disabled={busy}>{busy ? 'Recording…' : 'Record Payment & Print Receipt'}</Btn>
        </>}>
        <div className="col gap-3">
          <ErrorBanner error={err} style={{ marginBottom: 0 }} />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '8px 16px', fontSize: 12.5, paddingBottom: 12, borderBottom: '1px solid var(--line)' }}>
            <div><span className="t-2">{payType === 'Interest' ? 'Outstanding Interest' : 'Outstanding Capital'}</span><br />
              <b style={{ fontFamily: 'JetBrains Mono', color: 'var(--bad)' }}>{money(payType === 'Interest' ? summary?.outstandingInterest : summary?.outstandingCapital)}</b></div>
          </div>
          <Field label="Amount"><Input type="number" value={amount} onChange={(e) => setAmount(e.target.value)}
            placeholder={`Up to ${money(payType === 'Interest' ? summary?.outstandingInterest : summary?.outstandingCapital)}`} /></Field>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16 }}>
            <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
              {SD_METHOD_OPTIONS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
            <Field label="Date">
              <Input type="date" value={paidAt} max={todayIso()} disabled={!payBackDated} onChange={(e) => setPaidAt(e.target.value)} />
              <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
                <input type="checkbox" checked={payBackDated} onChange={(e) => {
                  const checked = e.target.checked
                  setPayBackDated(checked)
                  if (!checked) setPaidAt(todayIso())
                }} />
                <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
              </label>
            </Field>
            {method === 'Cash'
              ? <Field label="Receipt No."><Input value={nextReceipt || '…'} readOnly disabled style={{ color: 'var(--tx-2)' }} /></Field>
              : <Field label="Reference No."><Input value={receiptNo} onChange={(e) => setReceiptNo(e.target.value)} /></Field>}
          </div>
          <Field label="Notes"><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ width: '100%', background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: '9px 12px', color: 'var(--tx-0)', fontSize: 13.5, fontFamily: 'Outfit', resize: 'vertical' }} /></Field>
        </div>
      </Modal>

      <Modal open={settleOpen} onClose={resetSettleForm} title="Settle Full Balance" sub={selected?.code}
        footer={<>
          <Btn variant="solid" onClick={resetSettleForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={submitSettlement} disabled={settleBusy}>{settleBusy ? 'Recording…' : 'Record Settlement & Print Receipt'}</Btn>
        </>}>
        <div className="col gap-3">
          <ErrorBanner error={settleErr} style={{ marginBottom: 0 }} />
          <div className="t-2" style={{ fontSize: 12.5 }}>This pays off all outstanding interest and the full remaining capital of <b style={{ fontFamily: 'JetBrains Mono' }}>{money(summary?.amountToSettleToday)}</b>, closing the Speed Draft immediately.</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16 }}>
            <Field label="Payment Method"><Select value={settleMethod} onChange={(e) => setSettleMethod(e.target.value as PaymentMethod)}>
              {SD_METHOD_OPTIONS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
            <Field label="Date">
              <Input type="date" value={settlePaidAt} max={todayIso()} disabled={!settleBackDated} onChange={(e) => setSettlePaidAt(e.target.value)} />
              <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
                <input type="checkbox" checked={settleBackDated} onChange={(e) => {
                  const checked = e.target.checked
                  setSettleBackDated(checked)
                  if (!checked) setSettlePaidAt(todayIso())
                }} />
                <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
              </label>
            </Field>
            {settleMethod === 'Cash'
              ? <Field label="Receipt No."><Input value={nextReceipt || '…'} readOnly disabled style={{ color: 'var(--tx-2)' }} /></Field>
              : <Field label="Reference No."><Input value={settleReceiptNo} onChange={(e) => setSettleReceiptNo(e.target.value)} /></Field>}
          </div>
          <Field label="Notes"><textarea value={settleNotes} onChange={(e) => setSettleNotes(e.target.value)} rows={2} style={{ width: '100%', background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: '9px 12px', color: 'var(--tx-0)', fontSize: 13.5, fontFamily: 'Outfit', resize: 'vertical' }} /></Field>
        </div>
      </Modal>

      <Modal open={!!viewPayment} onClose={() => setViewPayment(null)} title={viewPayment ? `Payment ${viewPayment.code}` : ''} sub={selected ? `${selected.code} — ${selected.customer?.fullName}` : undefined}
        footer={<Btn variant="solid" onClick={() => setViewPayment(null)}>Close</Btn>}>
        {viewPayment && (
          <div className="col gap-2" style={{ fontSize: 13 }}>
            <div className="row between"><span className="t-2">Date</span><b>{dateOnly(viewPayment.paidAt)}</b></div>
            <div className="row between"><span className="t-2">Status</span><SpeedDraftPaymentStatusBadge p={viewPayment} /></div>
            <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}>
              <span className="t-2">Amount</span><span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(viewPayment.amount)}</span>
            </div>
            <div className="row between"><span className="t-2">Type</span><b>{SD_PAYMENT_TYPE_LABEL[viewPayment.paymentType]}</b></div>
            <div className="row between"><span className="t-2">Payment Method</span><b>{SD_METHOD_OPTIONS.find((m) => m.value === viewPayment.paymentMethod)?.label || viewPayment.paymentMethod}</b></div>
            {viewPayment.receiptNo && <div className="row between"><span className="t-2">Reference No.</span><b>{viewPayment.receiptNo}</b></div>}
            {viewPayment.notes && <div className="row between"><span className="t-2">Notes</span><b>{viewPayment.notes}</b></div>}
            <div className="row between" style={{ paddingTop: 8, borderTop: '1px solid var(--line)' }}><span className="t-2">Received By</span><b>{viewPayment.receiver?.name || '—'}</b></div>
            {viewPayment.status === 'Voided' && (
              <div className="col gap-1" style={{ marginTop: 8, padding: '8px 12px', background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
                <div>Voided {dateOnly(viewPayment.voidedAt)} by {viewPayment.voider?.name || '—'}</div>
                {viewPayment.voidReason && <div>Reason: {viewPayment.voidReason}</div>}
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}

export default PaymentReceiptScreen
