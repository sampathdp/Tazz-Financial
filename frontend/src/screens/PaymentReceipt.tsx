/* Tazz Investment & Credit — Payment Receipt: standalone loan-payment intake.
   Look up any active loan by NIC, Loan ID, or customer name, see its upcoming
   installments, then pay a row (any day, on or off schedule). Skipping an
   installment is an admin-only action — see the Loan Adjustments screen.
   Full settlement lives in the Loan Summary card — Cashier-only, and doesn't
   close the loan or become printable until a Branch Manager approves it.
   Posts to the same loans/{loan}/payments endpoint the Active Loans > Receipts
   tab uses, so history and outstanding balances stay in sync either way. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Table, Td, Modal, inputStyle } from '../components/ui'
import { Icon } from '../components/Icon'
import { loansApi, loanPaymentsApi, loanPostDatedChequesApi, company, type Loan, type LoanPayment, type LoanSchedule, type PaymentMethod, type AuthUser } from '../api'
import { Can, userCan } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const outstandingOf = (loan: Loan) => {
  const schedules = loan.schedules || []
  const totalDue = schedules.reduce((s, r) => s + num(r.totalDue), 0)
  const totalPaid = schedules.reduce((s, r) => s + num(r.paidAmount), 0)
  return Math.max(0, totalDue - totalPaid)
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

interface ReceiptData { payment: LoanPayment; loan: Loan; balanceBefore: number }

/* A Cheque payment applies to the balance immediately, same as Cash/Bank — it
   just also captures bank + cheque date for the audit trail. This is distinct
   from "Record Cheque" (Post Dated Cheques), which logs a future cheque
   without touching the balance until it's realized. */
function PaymentFields({ method, setMethod, paidAt, setPaidAt, refNo, setRefNo, bankName, setBankName, chequeDate, setChequeDate, notes, setNotes }: {
  method: PaymentMethod; setMethod: (v: PaymentMethod) => void
  paidAt: string; setPaidAt: (v: string) => void
  refNo: string; setRefNo: (v: string) => void
  bankName: string; setBankName: (v: string) => void
  chequeDate: string; setChequeDate: (v: string) => void
  notes: string; setNotes: (v: string) => void
}) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16 }}>
      <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
        {METHOD_OPTIONS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
      </Select></Field>
      <Field label="Date"><Input type="date" value={paidAt} onChange={(e) => setPaidAt(e.target.value)} /></Field>
      <Field label={REF_LABEL[method]}><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>
      {method === 'Cheque' && <>
        <Field label="Bank"><Input value={bankName} onChange={(e) => setBankName(e.target.value)} /></Field>
        <Field label="Cheque Date"><Input type="date" value={chequeDate} onChange={(e) => setChequeDate(e.target.value)} /></Field>
      </>}
      <Field label="Notes" full><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
    </div>
  )
}

export function PaymentReceiptScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [loans, setLoans] = useState<Loan[]>([])
  const [loading, setLoading] = useState(true)
  const [brand, setBrand] = useState({ name: '', tagline: '' })

  const [selected, setSelected] = useState<Loan | null>(null)
  const [loadingLoan, setLoadingLoan] = useState(false)

  // Pay-one-installment modal
  const [payTarget, setPayTarget] = useState<LoanSchedule | null>(null)
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<PaymentMethod>('Cash')
  const [paidAt, setPaidAt] = useState(todayIso())
  const [refNo, setRefNo] = useState('')
  const [payBankName, setPayBankName] = useState('')
  const [payChequeDate, setPayChequeDate] = useState(todayIso())
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  // Full-settlement modal
  const [settleOpen, setSettleOpen] = useState(false)
  const [settleMethod, setSettleMethod] = useState<PaymentMethod>('Cash')
  const [settlePaidAt, setSettlePaidAt] = useState(todayIso())
  const [settleRefNo, setSettleRefNo] = useState('')
  const [settleBankName, setSettleBankName] = useState('')
  const [settleChequeDate, setSettleChequeDate] = useState(todayIso())
  const [settleNotes, setSettleNotes] = useState('')
  const [settleBusy, setSettleBusy] = useState(false)
  const [settleErr, setSettleErr] = useState('')

  // Record-cheque modal (Post Dated Cheques) — a record only, never applied to the balance.
  const [chequeOpen, setChequeOpen] = useState(false)
  const [chequeNo, setChequeNo] = useState('')
  const [rcBankName, setRcBankName] = useState('')
  const [rcChequeDate, setRcChequeDate] = useState(todayIso())
  const [chequeAmount, setChequeAmount] = useState('')
  const [chequeBusy, setChequeBusy] = useState(false)
  const [chequeErr, setChequeErr] = useState('')

  const [receipt, setReceipt] = useState<ReceiptData | null>(null)
  const [scheduleTab, setScheduleTab] = useState<'all' | 'paid' | 'unpaid'>('unpaid')

  const refresh = () => {
    setLoading(true)
    loansApi.list().then(setLoans).catch(() => setLoans([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
  }, [])

  const payable = loans.filter((l) => l.status === 'Active')
  const outstanding = selected ? outstandingOf(selected) : 0
  const pendingSettlement = selected?.payments?.find((p) => p.isSettlement && p.approvalStatus === 'PendingApproval')
  const allSchedules = (selected?.schedules || []).slice().sort((a, b) => a.installmentNo - b.installmentNo)
  const scheduleRows = scheduleTab === 'paid' ? allSchedules.filter((s) => s.status === 'Paid')
    : scheduleTab === 'unpaid' ? allSchedules.filter((s) => s.status !== 'Paid')
    : allSchedules
  const locked = !!pendingSettlement

  const resetPayForm = () => { setPayTarget(null); setAmount(''); setMethod('Cash'); setPaidAt(todayIso()); setRefNo(''); setPayBankName(''); setPayChequeDate(todayIso()); setNotes(''); setErr('') }
  const resetSettleForm = () => { setSettleOpen(false); setSettleMethod('Cash'); setSettlePaidAt(todayIso()); setSettleRefNo(''); setSettleBankName(''); setSettleChequeDate(todayIso()); setSettleNotes(''); setSettleErr('') }
  const resetChequeForm = () => { setChequeOpen(false); setChequeNo(''); setRcBankName(''); setRcChequeDate(todayIso()); setChequeAmount(''); setChequeErr('') }

  const pickLoan = async (_n: string, opt?: Loan) => {
    resetPayForm(); resetSettleForm(); resetChequeForm(); setScheduleTab('unpaid')
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

  const openPay = (row: LoanSchedule) => {
    setPayTarget(row); setAmount(String(balanceOf(row))); setMethod('Cash'); setPaidAt(todayIso()); setRefNo(''); setPayBankName(''); setPayChequeDate(todayIso()); setNotes(''); setErr('')
  }

  const record = async () => {
    if (!selected) return
    if (!amount || Number(amount) <= 0) { setErr('Enter a payment amount.'); return }
    if (method === 'Cheque' && (!payBankName || !payChequeDate)) { setErr('Bank and Cheque Date are required for a cheque payment.'); return }
    setBusy(true); setErr('')
    try {
      const balanceBefore = outstandingOf(selected)
      const payment = await loanPaymentsApi.create(selected.id, {
        amount, paymentMethod: method, paidAt, receiptNo: refNo || undefined,
        bankName: method === 'Cheque' ? payBankName : undefined,
        chequeDate: method === 'Cheque' ? payChequeDate : undefined,
        notes: notes || undefined,
      })
      const fresh = await loansApi.get(selected.id)
      setReceipt({ payment, loan: fresh, balanceBefore })
      refresh()
    } catch (ex: any) {
      setErr(ex?.response?.data?.message || 'Payment failed')
    } finally { setBusy(false) }
  }

  const submitSettlement = async () => {
    if (!selected) return
    if (settleMethod === 'Cheque' && (!settleBankName || !settleChequeDate)) { setSettleErr('Bank and Cheque Date are required for a cheque settlement.'); return }
    setSettleBusy(true); setSettleErr('')
    try {
      await loanPaymentsApi.create(selected.id, {
        amount: outstanding, paymentMethod: settleMethod, paidAt: settlePaidAt,
        receiptNo: settleRefNo || undefined,
        bankName: settleMethod === 'Cheque' ? settleBankName : undefined,
        chequeDate: settleMethod === 'Cheque' ? settleChequeDate : undefined,
        notes: settleNotes || undefined, isSettlement: true,
      })
      resetSettleForm()
      notify.success('Settlement request submitted', 'Awaiting Branch Manager approval')
      await reloadSelected()
    } catch (ex: any) {
      setSettleErr(ex?.response?.data?.message || 'Could not submit the settlement request.')
    } finally { setSettleBusy(false) }
  }

  const submitCheque = async () => {
    if (!selected) return
    if (!chequeNo || !rcBankName || !chequeAmount) { setChequeErr('Cheque no., bank, and amount are required.'); return }
    setChequeBusy(true); setChequeErr('')
    try {
      await loanPostDatedChequesApi.create(selected.id, { chequeNo, bankName: rcBankName, chequeDate: rcChequeDate, amount: chequeAmount })
      resetChequeForm()
      notify.success('Cheque recorded', 'Track it on the loan\'s Post Dated Cheques tab')
    } catch (ex: any) {
      setChequeErr(ex?.response?.data?.message || 'Could not record the cheque.')
    } finally { setChequeBusy(false) }
  }

  const approveSettlement = async () => {
    if (!selected || !pendingSettlement) return
    setBusy(true)
    try {
      const balanceBefore = num(pendingSettlement.amount)
      const approved = await loanPaymentsApi.approveSettlement(pendingSettlement.id)
      const fresh = await loansApi.get(selected.id)
      setReceipt({ payment: approved, loan: fresh, balanceBefore })
      refresh()
    } catch (ex: any) {
      notify.error('Approval failed', ex?.response?.data?.message)
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
    } catch (ex: any) { notify.error('Rejection failed', ex?.response?.data?.message) }
  }

  const startOver = () => {
    setReceipt(null); setSelected(null)
    resetPayForm(); resetSettleForm(); resetChequeForm()
  }

  if (receipt) {
    const { payment, loan, balanceBefore } = receipt
    const balanceAfter = outstandingOf(loan)
    const pendingClearance = payment.paymentMethod === 'Cheque' && payment.clearanceStatus === 'Pending'
    return (
      <div>
        <div className="row between wrap gap-2 no-print" style={{ marginBottom: 16 }}>
          <PageHead crumbs="Loan Management" title="Payment Receipt" icon="receipt"
            sub={payment.isSettlement ? 'Settlement approved — loan closed. Print or issue the receipt below.'
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
            <div style={{ fontWeight: 700, fontSize: 14, letterSpacing: '.08em', marginTop: 8 }}>{payment.isSettlement ? 'FULL SETTLEMENT RECEIPT' : 'PAYMENT RECEIPT'}</div>
          </div>

          <div className="row between" style={{ marginBottom: 14, fontSize: 13 }}>
            <div><span className="t-2">Receipt No: </span><b>{payment.code}</b></div>
            <div><span className="t-2">Date: </span><b>{dateOnly(payment.paidAt)}</b></div>
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Received From</span><b>{loan.customer?.fullName}</b></div>
            <div className="row between"><span className="t-2">NIC No</span><b>{loan.customer?.nicNo || '—'}</b></div>
            <div className="row between"><span className="t-2">Loan / Contract No</span><b>{loan.code}</b></div>
            <div className="row between"><span className="t-2">Product</span><b>{loan.loanType?.name || '—'}</b></div>
            {payment.isSettlement && <div className="row between"><span className="t-2">Loan Status</span><b>Closed</b></div>}
          </div>

          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Payment Method</span><b>{METHOD_OPTIONS.find((m) => m.value === payment.paymentMethod)?.label || payment.paymentMethod}</b></div>
            {payment.receiptNo && <div className="row between"><span className="t-2">{REF_LABEL[payment.paymentMethod]}</span><b>{payment.receiptNo}</b></div>}
            {payment.paymentMethod === 'Cheque' && payment.bankName && <div className="row between"><span className="t-2">Bank</span><b>{payment.bankName}</b></div>}
            {payment.paymentMethod === 'Cheque' && payment.chequeDate && <div className="row between"><span className="t-2">Cheque Date</span><b>{dateOnly(payment.chequeDate)}</b></div>}
            {payment.notes && <div className="row between"><span className="t-2">Notes</span><b>{payment.notes}</b></div>}
            {payment.isSettlement && <div className="row between"><span className="t-2">Approved By</span><b>{payment.approver?.name || '—'}</b></div>}
          </div>

          {pendingClearance && (
            <div className="no-print" style={{ background: 'var(--warn-dim)', color: 'var(--warn)', border: '1px solid var(--warn)', padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5, marginBottom: 16 }}>
              This cheque is held pending clearance and has not been applied to the balance. Realize or reject it on the Cheque Management screen once it clears (or bounces) at the bank.
            </div>
          )}
          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 20 }}>
            <div className="row between"><span className="t-2">Balance Before</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(balanceBefore)}</span></div>
            <div className="row between"><span className="t-2">{pendingClearance ? 'Cheque Amount (pending)' : 'Amount Paid'}</span><span style={{ fontFamily: 'JetBrains Mono', fontWeight: 700 }}>{money(payment.amount)}</span></div>
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
        sub="Look up a loan by NIC, Loan ID, or customer name, then pay an installment or settle the loan completely." />

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
            <div className="row between wrap gap-2" style={{ marginBottom: 10 }}>
              <div className="eyebrow">Loan Summary</div>
              <div className="row gap-2">
                <Can user={me} module="Loan Payments" action="Create">
                  <Btn variant="solid" size="sm" icon="cheque" onClick={() => setChequeOpen(true)}>Record Cheque</Btn>
                </Can>
                {isCashier(me) && outstanding > 0 && !locked && (
                  <Btn variant="solid" size="sm" icon="flag" onClick={() => setSettleOpen(true)}>Settle Loan Completely</Btn>
                )}
              </div>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5 }}>
              <div><span className="t-2">Customer: </span><b>{selected.customer?.fullName}</b></div>
              <div><span className="t-2">NIC: </span><b>{selected.customer?.nicNo || '—'}</b></div>
              <div><span className="t-2">Loan Code: </span><b>{selected.code}</b></div>
              <div><span className="t-2">Product: </span><b>{selected.loanType?.name || '—'}</b></div>
              <div><span className="t-2">Status: </span><Badge tone="green" dot>{selected.status}</Badge></div>
              <div><span className="t-2">Outstanding Balance: </span><b style={{ fontFamily: 'JetBrains Mono', color: outstanding > 0 ? 'var(--bad)' : 'var(--ok)' }}>{money(outstanding)}</b></div>
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
                {pendingSettlement.paymentMethod === 'Cheque' && pendingSettlement.bankName && <div><span className="t-2">Bank: </span><b>{pendingSettlement.bankName}</b></div>}
                {pendingSettlement.paymentMethod === 'Cheque' && pendingSettlement.chequeDate && <div><span className="t-2">Cheque Date: </span><b>{dateOnly(pendingSettlement.chequeDate)}</b></div>}
              </div>
              {userCan(me, 'Loan Payments', 'Approve') ? (
                <div className="row gap-2">
                  <Btn variant="primary" icon="check" onClick={approveSettlement} disabled={busy}>{busy ? 'Approving…' : 'Approve & Close Loan'}</Btn>
                  <Btn variant="danger" icon="x" onClick={rejectSettlement} disabled={busy}>Reject</Btn>
                </div>
              ) : (
                <div className="t-2" style={{ fontSize: 12.5 }}>Awaiting Branch Manager approval — the loan will close and the receipt can be printed once approved.</div>
              )}
            </Card>
          )}

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
            <Table cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Total Due', align: 'right' }, { label: 'Paid', align: 'right' }, { label: 'Balance', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 90 }]}
              rows={scheduleRows} empty={scheduleTab === 'paid' ? 'No paid installments yet' : scheduleTab === 'unpaid' ? 'No unpaid installments — this loan is fully paid' : 'No installments generated'}
              render={(r: LoanSchedule) => <>
                <Td>{r.installmentNo}</Td>
                <Td>{dateOnly(r.dueDate)}</Td>
                <Td mono align="right">{money(r.totalDue)}</Td>
                <Td mono align="right">{money(r.paidAmount)}</Td>
                <Td mono align="right">{money(balanceOf(r))}</Td>
                <Td align="center"><Badge tone={scheduleTone[r.status]} dot>{r.status}</Badge></Td>
                <Td align="right">
                  {r.status !== 'Paid' && (
                    <Can user={me} module="Loan Payments" action="Create">
                      <Btn size="sm" variant="primary" icon="check" onClick={() => openPay(r)} disabled={locked}>Pay</Btn>
                    </Can>
                  )}
                </Td>
              </>} />
          </Card>
        </>
      )}

      {!selected && !loading && !loadingLoan && (
        <div className="t-2" style={{ fontSize: 12.5, textAlign: 'center', padding: '20px 0' }}>
          <Icon n="search" s={16} /> Search above to find a loan by NIC number, loan code, or customer name.
        </div>
      )}

      <Modal open={!!payTarget} onClose={resetPayForm} title={`Pay Installment #${payTarget?.installmentNo ?? ''}`} sub={payTarget ? `Due ${dateOnly(payTarget.dueDate)} — payments can be made any day, before or after the due date.` : undefined}
        footer={<>
          <Btn variant="solid" onClick={resetPayForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={record} disabled={busy}>{busy ? 'Recording…' : 'Record Payment & Print Receipt'}</Btn>
        </>}>
        <div className="col gap-3">
          {err && <div style={{ background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>{err}</div>}
          <Field label="Amount"><Input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={`Outstanding: ${money(outstanding)}`} /></Field>
          <PaymentFields method={method} setMethod={setMethod} paidAt={paidAt} setPaidAt={setPaidAt} refNo={refNo} setRefNo={setRefNo}
            bankName={payBankName} setBankName={setPayBankName} chequeDate={payChequeDate} setChequeDate={setPayChequeDate} notes={notes} setNotes={setNotes} />
        </div>
      </Modal>

      <Modal open={settleOpen} onClose={resetSettleForm} title="Settle Loan Completely" sub={selected?.code}
        footer={<>
          <Btn variant="solid" onClick={resetSettleForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={submitSettlement} disabled={settleBusy}>{settleBusy ? 'Submitting…' : 'Submit for Approval'}</Btn>
        </>}>
        <div className="col gap-3">
          {settleErr && <div style={{ background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>{settleErr}</div>}
          <div className="t-2" style={{ fontSize: 12.5 }}>This pays off the full outstanding balance of <b style={{ fontFamily: 'JetBrains Mono' }}>{money(outstanding)}</b> and must be approved by a Branch Manager before the loan closes and the receipt can be printed.</div>
          <PaymentFields method={settleMethod} setMethod={setSettleMethod} paidAt={settlePaidAt} setPaidAt={setSettlePaidAt} refNo={settleRefNo} setRefNo={setSettleRefNo}
            bankName={settleBankName} setBankName={setSettleBankName} chequeDate={settleChequeDate} setChequeDate={setSettleChequeDate} notes={settleNotes} setNotes={setSettleNotes} />
        </div>
      </Modal>

      <Modal open={chequeOpen} onClose={resetChequeForm} title="Record Cheque" sub={selected?.code}
        footer={<>
          <Btn variant="solid" onClick={resetChequeForm}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={submitCheque} disabled={chequeBusy}>{chequeBusy ? 'Recording…' : 'Record Cheque'}</Btn>
        </>}>
        <div className="col gap-3">
          {chequeErr && <div style={{ background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>{chequeErr}</div>}
          <div className="t-2" style={{ fontSize: 12.5 }}>This is a record only — it won't reduce the outstanding balance until the cheque is marked Realized on the loan's Post Dated Cheques tab.</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16 }}>
            <Field label="Cheque No"><Input value={chequeNo} onChange={(e) => setChequeNo(e.target.value)} /></Field>
            <Field label="Bank"><Input value={rcBankName} onChange={(e) => setRcBankName(e.target.value)} /></Field>
            <Field label="Cheque Date"><Input type="date" value={rcChequeDate} onChange={(e) => setRcChequeDate(e.target.value)} /></Field>
            <Field label="Amount"><Input type="number" value={chequeAmount} onChange={(e) => setChequeAmount(e.target.value)} /></Field>
          </div>
        </div>
      </Modal>
    </div>
  )
}

export default PaymentReceiptScreen
