/* Funding Management — Deposit Payment: standalone FD-payment intake, the
   Fixed Deposit counterpart of Loan Management's Payment Receipt screen.
   Look up any active FD by FD No, NIC, or customer name, then route a
   payment through exactly one of three flows — pay the next due monthly
   interest installment, make a premature part payment (partial principal
   withdrawal that keeps the FD open, reduced), or withdraw in full at
   maturity. Posts to the same endpoints Fixed Deposits' own detail screen
   uses (fdInterestPaymentsApi.pay / fixedDepositsApi.partPayment / .withdraw),
   so history and balances stay in sync either way — this screen is just a
   second front door, same relationship PaymentReceipt.tsx has to LoanDetail. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, SearchSelect, Table, Td, Modal, inputStyle, ErrorBanner , BankAccountSelect } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  fixedDepositsApi, fdInterestPaymentsApi, company,
  type FixedDeposit, type FdInterestPayment, type FdWithdrawal, type FdPartPayment,
  type FdPaymentMethod, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)
const daysBetween = (a: string, b: string) => Math.max(1, Math.round((new Date(b).getTime() - new Date(a).getTime()) / 86400000))

const METHODS: { value: FdPaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' },
  { value: 'Cheque', label: 'Cheque' },
  { value: 'Bank', label: 'Bank Transfer' },
]

type PrintDoc =
  | { kind: 'interest'; payment: FdInterestPayment; fd: FixedDeposit }
  | { kind: 'partpayment'; partPayment: FdPartPayment; fd: FixedDeposit }
  | { kind: 'withdrawal'; withdrawal: FdWithdrawal; fd: FixedDeposit }

function DocHeader({ brand, title }: { brand: { name: string; tagline: string }; title: string }) {
  return (
    <div className="col gap-1" style={{ textAlign: 'center', marginBottom: 18, paddingBottom: 18, borderBottom: '1px dashed var(--line)' }}>
      <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 20 }}>{brand.name || 'Company'}</div>
      {brand.tagline && <div className="t-2" style={{ fontSize: 12 }}>{brand.tagline}</div>}
      <div style={{ fontWeight: 700, fontSize: 14, letterSpacing: '.08em', marginTop: 8 }}>{title}</div>
    </div>
  )
}
function DocRow({ label, value }: { label: string; value: React.ReactNode }) {
  return <div className="row between"><span className="t-2">{label}</span><b>{value}</b></div>
}

function PrintView({ doc, brand, onClose }: { doc: PrintDoc; brand: { name: string; tagline: string }; onClose: () => void }) {
  return (
    <div>
      <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
        <Btn variant="primary" icon="print" onClick={() => window.print()}>Print</Btn>
        <Btn variant="solid" icon="x" onClick={onClose}>Close</Btn>
      </div>
      <Card style={{ maxWidth: 560, margin: '0 auto', fontFamily: 'Outfit' }}>
        {doc.kind === 'interest' && (() => {
          const { payment, fd } = doc
          return <>
            <DocHeader brand={brand} title="INTEREST PAYMENT RECEIPT" />
            <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
              <DocRow label="FD No" value={fd.fdNumber} />
              <DocRow label="Customer" value={fd.customer?.fullName || '—'} />
              <DocRow label="NIC No" value={fd.customer?.nicNo || '—'} />
              <DocRow label="Installment #" value={payment.installmentNo} />
              <DocRow label="Due Date" value={dateOnly(payment.dueDate)} />
              <DocRow label="Paid Date" value={dateOnly(payment.paidAt)} />
              <DocRow label="Payment Method" value={payment.paymentMethod || '—'} />
            </div>
            <div className="row between" style={{ fontSize: 15, fontWeight: 700 }}>
              <span>Amount Paid</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(payment.paidAmount)}</span>
            </div>
          </>
        })()}

        {doc.kind === 'partpayment' && (() => {
          const { partPayment, fd } = doc
          return <>
            <DocHeader brand={brand} title="PART PAYMENT VOUCHER" />
            <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
              <DocRow label="Voucher No" value={partPayment.voucherNo} />
              <DocRow label="FD No" value={fd.fdNumber} />
              <DocRow label="Customer" value={fd.customer?.fullName || '—'} />
              <DocRow label="Paid Date" value={dateOnly(partPayment.paidAt)} />
              <DocRow label="Payment Method" value={partPayment.paymentMethod} />
              <DocRow label="Held" value={`${partPayment.heldDays} days @ ${Number(partPayment.ratePercent).toFixed(2)}%`} />
            </div>
            <div className="col gap-2" style={{ fontSize: 13, marginBottom: 20 }}>
              <DocRow label="Principal Withdrawn" value={money(partPayment.principalAmount)} />
              {Number(partPayment.interestPaid) > 0 && <DocRow label="Interest Paid" value={money(partPayment.interestPaid)} />}
              {Number(partPayment.clawbackAmount) > 0 && <DocRow label="Interest Clawback" value={`(${money(partPayment.clawbackAmount)})`} />}
              <div className="row between" style={{ fontSize: 15, fontWeight: 700, paddingTop: 6, borderTop: '1px solid var(--line)' }}>
                <span>Net Amount Paid</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(partPayment.netAmountPaid)}</span>
              </div>
              <DocRow label="Remaining Principal" value={money(fd.principalAmount)} />
            </div>
          </>
        })()}

        {doc.kind === 'withdrawal' && (() => {
          const { withdrawal, fd } = doc
          return <>
            <DocHeader brand={brand} title="WITHDRAWAL VOUCHER" />
            <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
              <DocRow label="Voucher No" value={withdrawal.voucherNo} />
              <DocRow label="FD No" value={fd.fdNumber} />
              <DocRow label="Customer" value={fd.customer?.fullName || '—'} />
              <DocRow label="Closed Date" value={dateOnly(withdrawal.closedAt)} />
              <DocRow label="Payment Method" value={withdrawal.paymentMethod} />
            </div>
            <div className="col gap-2" style={{ fontSize: 13, marginBottom: 20 }}>
              <DocRow label="Principal" value={money(withdrawal.principalAmount)} />
              <DocRow label="Interest" value={money(withdrawal.interestAmount)} />
              <div className="row between" style={{ fontSize: 15, fontWeight: 700, paddingTop: 6, borderTop: '1px solid var(--line)' }}>
                <span>Total Paid</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(withdrawal.totalAmount)}</span>
              </div>
            </div>
          </>
        })()}

        <div className="row between" style={{ fontSize: 12, marginTop: 30 }}>
          <div>Received By: ______________</div>
          <div>Signature: ______________</div>
        </div>
      </Card>
    </div>
  )
}

/* ============================ Pay interest modal ============================ */

function PayInterestModal({ payment, open, onClose, onPaid }: { payment: FdInterestPayment | null; open: boolean; onClose: () => void; onPaid: (p: FdInterestPayment) => void }) {
  const notify = useNotify()
  const [method, setMethod] = useState<FdPaymentMethod>('Cash')
  const [bankAccountId, setBankAccountId] = useState<number | undefined>(undefined)
  const [refNo, setRefNo] = useState('')
  const [paidAt, setPaidAt] = useState(todayIso())
  const [backDated, setBackDated] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { if (open) { setMethod('Cash'); setBankAccountId(undefined); setRefNo(''); setPaidAt(todayIso()); setBackDated(false); setErr('') } }, [open])
  if (!payment) return null

  const save = async () => {
    if (method !== 'Cash' && !bankAccountId) { setErr('Choose the company bank account this payment is made from.'); return }
    setBusy(true); setErr('')
    try {
      const result = await fdInterestPaymentsApi.pay(payment.id, { paymentMethod: method, bankAccountId: method !== 'Cash' ? bankAccountId : undefined, referenceNo: refNo || undefined, paidAt })
      onPaid(result)
      notify.success('Interest payment recorded')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not record this interest payment.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={460} title={`Pay Interest — Installment #${payment.installmentNo}`} sub={`Due ${dateOnly(payment.dueDate)} — Amount ${money(payment.amount)}`}
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Recording...' : 'Record & Print Receipt'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as FdPaymentMethod)}>
          {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
        </Select></Field>
        {method !== 'Cash' && <Field label="Paid from — company bank account"><BankAccountSelect value={bankAccountId} onChange={setBankAccountId} /></Field>}
        <Field label="Date">
          <DateInput value={paidAt} disabled={!backDated} onChange={setPaidAt} />
          <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
            <input type="checkbox" checked={backDated} onChange={(e) => {
              const checked = e.target.checked
              setBackDated(checked)
              if (!checked) setPaidAt(todayIso())
            }} />
            <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
          </label>
        </Field>
        <Field label="Reference No" full><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>
      </div>
    </Modal>
  )
}

/* ============================ Withdraw modal ============================ */

function WithdrawModal({ fd, open, onClose, onWithdrawn }: { fd: FixedDeposit | null; open: boolean; onClose: () => void; onWithdrawn: (withdrawal: FdWithdrawal, fd: FixedDeposit) => void }) {
  const notify = useNotify()
  const [method, setMethod] = useState<FdPaymentMethod>('Cash')
  const [bankAccountId, setBankAccountId] = useState<number | undefined>(undefined)
  const [refNo, setRefNo] = useState('')
  const [notes, setNotes] = useState('')
  const [closedAt, setClosedAt] = useState(todayIso())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { if (open) { setMethod('Cash'); setBankAccountId(undefined); setRefNo(''); setNotes(''); setClosedAt(todayIso()); setErr('') } }, [open])
  if (!fd) return null

  const interestPayable = fd.interestPaymentMethod === 'maturity' ? Number(fd.totalInterestAmount) : 0
  const total = Number(fd.principalAmount) + interestPayable

  const save = async () => {
    if (method !== 'Cash' && !bankAccountId) { setErr('Choose the company bank account this payment is made from.'); return }
    setBusy(true); setErr('')
    try {
      const result = await fixedDepositsApi.withdraw(fd.id, { paymentMethod: method, bankAccountId: method !== 'Cash' ? bankAccountId : undefined, referenceNo: refNo || undefined, notes: notes || undefined, closedAt })
      onWithdrawn(result, result.fixedDeposit)
      notify.success('Fixed Deposit withdrawn', fd.fdNumber)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not withdraw this Fixed Deposit.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={520} title={`Withdraw ${fd.fdNumber}`}
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Processing...' : 'Withdraw & Print Voucher'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div className="col gap-3">
        <div style={{ background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: 14, fontSize: 12.5 }}>
          <div className="row between"><span className="t-2">Principal</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(fd.principalAmount)}</b></div>
          <div className="row between"><span className="t-2">Interest</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(interestPayable)}</b></div>
          <div className="row between" style={{ fontWeight: 700, marginTop: 4 }}><span>Total Payable</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(total)}</b></div>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as FdPaymentMethod)}>
            {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </Select></Field>
          {method !== 'Cash' && <Field label="Paid from — company bank account"><BankAccountSelect value={bankAccountId} onChange={setBankAccountId} /></Field>}
          <Field label="Closed Date"><DateInput value={closedAt} onChange={setClosedAt} /></Field>
          <Field label="Reference No"><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>
        </div>
        <Field label="Notes" full><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>
    </Modal>
  )
}

/* ============================ Part payment modal ============================ */

/** Client-side preview only — mirrors App\Http\Controllers\Api\FixedDepositController::partPayment()'s
 *  math for display; the server always computes the authoritative figures. */
function previewPartPayment(fd: FixedDeposit, amount: number, paidAt: string) {
  const rate = Number(fd.fundingProduct?.prematureWithdrawalRatePercent ?? 0)
  const principal = Number(fd.principalAmount)
  if (!rate || !amount || amount <= 0 || amount >= principal) return null
  const heldDays = daysBetween(fd.startDate, paidAt)
  const entitlement = Math.round(amount * (rate / 100) * (heldDays / 365) * 100) / 100

  let interestPaid = 0
  let clawback = 0
  if (fd.interestPaymentMethod === 'monthly') {
    const paidSoFar = (fd.interestPayments || []).filter((p) => p.status === 'Paid').reduce((s, p) => s + Number(p.paidAmount || 0), 0)
    const attributed = Math.round(paidSoFar * (amount / principal) * 100) / 100
    clawback = Math.max(0, Math.round((attributed - entitlement) * 100) / 100)
  } else {
    interestPaid = entitlement
  }
  const netAmount = Math.round((amount + interestPaid - clawback) * 100) / 100
  return { heldDays, rate, entitlement, interestPaid, clawback, netAmount, remainingPrincipal: Math.round((principal - amount) * 100) / 100 }
}

function PartPaymentModal({ fd, open, onClose, onPaid }: { fd: FixedDeposit | null; open: boolean; onClose: () => void; onPaid: (pp: FdPartPayment, fd: FixedDeposit) => void }) {
  const notify = useNotify()
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<FdPaymentMethod>('Cash')
  const [bankAccountId, setBankAccountId] = useState<number | undefined>(undefined)
  const [refNo, setRefNo] = useState('')
  const [notes, setNotes] = useState('')
  const [paidAt, setPaidAt] = useState(todayIso())
  const [backDated, setBackDated] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { if (open) { setAmount(''); setMethod('Cash'); setBankAccountId(undefined); setRefNo(''); setNotes(''); setPaidAt(todayIso()); setBackDated(false); setErr('') } }, [open])
  if (!fd) return null

  const rate = fd.fundingProduct?.prematureWithdrawalRatePercent
  const preview = rate != null ? previewPartPayment(fd, Number(amount), paidAt) : null

  const save = async () => {
    if (method !== 'Cash' && !bankAccountId) { setErr('Choose the company bank account this payment is made from.'); return }
    if (!amount || Number(amount) <= 0) { setErr('Enter an amount to withdraw.'); return }
    setBusy(true); setErr('')
    try {
      const result = await fixedDepositsApi.partPayment(fd.id, { amount, paymentMethod: method, bankAccountId: method !== 'Cash' ? bankAccountId : undefined, referenceNo: refNo || undefined, notes: notes || undefined, paidAt })
      onPaid(result, result.fixedDeposit)
      notify.success('Part payment recorded', fd.fdNumber)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not record this part payment.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={520} title={`Part Payment — ${fd.fdNumber}`} sub={`Current principal ${money(fd.principalAmount)}`}
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy || rate == null}>{busy ? 'Processing...' : 'Record & Print Voucher'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      {rate == null ? (
        <div style={{ background: 'var(--warn-dim)', color: 'var(--warn)', border: '1px solid var(--warn)', padding: '10px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5 }}>
          This funding product has no premature withdrawal rate configured — set one on Funding Products before part payments can be recorded.
        </div>
      ) : (
        <div className="col gap-3">
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Amount to Withdraw"><Input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
            <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as FdPaymentMethod)}>
              {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
            {method !== 'Cash' && <Field label="Paid from — company bank account"><BankAccountSelect value={bankAccountId} onChange={setBankAccountId} /></Field>}
            <Field label="Date">
              <DateInput value={paidAt} disabled={!backDated} onChange={setPaidAt} />
              <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
                <input type="checkbox" checked={backDated} onChange={(e) => {
                  const checked = e.target.checked
                  setBackDated(checked)
                  if (!checked) setPaidAt(todayIso())
                }} />
                <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
              </label>
            </Field>
            <Field label="Reference No"><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>
          </div>
          <Field label="Notes" full><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>

          {preview && (
            <div style={{ background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: 14 }}>
              <div className="eyebrow" style={{ marginBottom: 6 }}>Preview (estimate — server computes the final figures)</div>
              <div className="col gap-2" style={{ fontSize: 12.5 }}>
                <div className="row between"><span className="t-2">Held</span><b>{preview.heldDays} days @ {preview.rate.toFixed(2)}% premature rate</b></div>
                <div className="row between"><span className="t-2">Interest Entitlement</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.entitlement)}</b></div>
                {preview.interestPaid > 0 && <div className="row between"><span className="t-2">Interest Paid Out</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.interestPaid)}</b></div>}
                {preview.clawback > 0 && <div className="row between"><span className="t-2">Clawback (from prior monthly payouts)</span><b style={{ fontFamily: 'JetBrains Mono', color: 'var(--bad)' }}>({money(preview.clawback)})</b></div>}
                <div className="row between" style={{ fontWeight: 700 }}><span>Net Amount Payable</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.netAmount)}</b></div>
                <div className="row between"><span className="t-2">Remaining Principal</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.remainingPrincipal)}</b></div>
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}

/* ============================ Screen ============================ */

export function DepositPaymentScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [fds, setFds] = useState<FixedDeposit[]>([])
  const [loading, setLoading] = useState(true)
  const [brand, setBrand] = useState({ name: '', tagline: '' })

  const [selected, setSelected] = useState<FixedDeposit | null>(null)
  const [loadingFd, setLoadingFd] = useState(false)
  const [tab, setTab] = useState<'schedule' | 'partpayments' | 'withdrawal'>('schedule')

  const [payTarget, setPayTarget] = useState<FdInterestPayment | null>(null)
  const [withdrawOpen, setWithdrawOpen] = useState(false)
  const [partPaymentOpen, setPartPaymentOpen] = useState(false)
  const [doc, setDoc] = useState<PrintDoc | null>(null)

  const refresh = () => {
    setLoading(true)
    fixedDepositsApi.list({ status: 'Active' }).then(setFds).catch(() => setFds([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
  }, [])

  const pickFd = async (_n: string, opt?: FixedDeposit) => {
    setPayTarget(null); setWithdrawOpen(false); setPartPaymentOpen(false); setTab('schedule')
    if (!opt) { setSelected(null); return }
    setLoadingFd(true)
    try { setSelected(await fixedDepositsApi.get(opt.id)) }
    catch { setSelected(opt) }
    finally { setLoadingFd(false) }
  }

  const reloadSelected = async () => {
    if (!selected) return
    try { setSelected(await fixedDepositsApi.get(selected.id)) } catch { /* keep stale copy */ }
  }

  const voidPartPayment = async (pp: FdPartPayment) => {
    const ok = await notify.confirm({ title: `Void part payment ${pp.voucherNo}?`, msg: 'Reverses its GL/Cashbook entries and restores the FD principal and interest schedule.', danger: true, okText: 'Void' })
    if (!ok) return
    try { await fixedDepositsApi.voidPartPayment(pp.id); notify.success('Part payment voided'); refresh(); await reloadSelected() }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  const voidWithdrawal = async () => {
    if (!selected) return
    const ok = await notify.confirm({ title: `Void the withdrawal on ${selected.fdNumber}?`, msg: 'Reverses its GL/Cashbook entries and reopens this FD as Active.', danger: true, okText: 'Void' })
    if (!ok) return
    try { await fixedDepositsApi.voidWithdrawal(selected.id); notify.success('Withdrawal voided'); refresh(); await reloadSelected() }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  const todayLabel = new Date().toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
  const schedules = (selected?.interestPayments || []).slice().sort((a, b) => a.installmentNo - b.installmentNo)
  const nextDue = schedules.find((p) => p.status === 'Pending' && p.dueDate.slice(0, 10) <= todayIso())
  const partPayments = selected?.partPayments || []
  const latestPartPaymentId = partPayments[0]?.id
  const canPayInterest = selected?.interestPaymentMethod === 'monthly' && !!nextDue
  const canWithdraw = !!selected?.isMatured
  const canPartPay = selected?.status === 'Active' && !selected?.isMatured

  if (doc) {
    return <PrintView doc={doc} brand={brand} onClose={() => setDoc(null)} />
  }

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Deposit Payment" icon="receipt"
        sub="Look up a Fixed Deposit by FD No, NIC, or customer name, then pay interest, make a part payment, or withdraw at maturity."
        actions={<div className="t-2" style={{ fontSize: 12.5 }}>Today: <b style={{ color: 'var(--tx-0)' }}>{todayLabel}</b></div>} />

      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Find Fixed Deposit</div>
        <SearchSelect
          value={selected ? `${selected.fdNumber} — ${selected.customer?.fullName}` : ''}
          onChange={(n, opt) => pickFd(n, opt)}
          options={fds}
          getLabel={(f: FixedDeposit) => `${f.fdNumber} — ${f.customer?.fullName}`}
          getSub={(f: FixedDeposit) => [f.customer?.nicNo, f.fundingProduct?.name].filter(Boolean).join(' · ')}
          placeholder={loading ? 'Loading Fixed Deposits…' : 'Search by FD No, NIC, or customer name…'}
          disabled={loading}
        />
      </Card>

      {loadingFd && <div style={{ padding: 30, textAlign: 'center', color: 'var(--tx-2)' }}>Loading Fixed Deposit…</div>}

      {selected && !loadingFd && (
        <>
          <Card style={{ marginBottom: 16 }}>
            <div className="row between wrap gap-2" style={{ marginBottom: 14 }}>
              <div className="eyebrow">Fixed Deposit Summary</div>
              <Can user={me} module="Fixed Deposits" action="Edit">
                <div className="row gap-2">
                  {canPayInterest && <Btn variant="primary" size="sm" icon="check" onClick={() => setPayTarget(nextDue!)}>Pay Interest</Btn>}
                  {canPartPay && <Btn variant="solid" size="sm" icon="download" onClick={() => setPartPaymentOpen(true)}>Part Payment</Btn>}
                  {canWithdraw && <Btn variant="solid" size="sm" icon="flag" onClick={() => setWithdrawOpen(true)}>Withdraw</Btn>}
                </div>
              </Can>
            </div>

            <div className="row wrap gap-3" style={{ alignItems: 'center', paddingBottom: 14, marginBottom: 14, borderBottom: '1px solid var(--line)' }}>
              <div>
                <div style={{ fontSize: 15, fontWeight: 700 }}>{selected.customer?.fullName}</div>
                <div className="t-2" style={{ fontSize: 11.5 }}>NIC {selected.customer?.nicNo || '—'}</div>
              </div>
              <div style={{ width: 1, height: 30, background: 'var(--line)' }} />
              <div>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em' }}>FD No</div>
                <div style={{ fontFamily: 'JetBrains Mono', fontWeight: 600, fontSize: 13 }}>{selected.fdNumber}</div>
              </div>
              <div style={{ width: 1, height: 30, background: 'var(--line)' }} />
              <div>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em' }}>Product</div>
                <div style={{ fontWeight: 600, fontSize: 13 }}>{selected.fundingProduct?.name || '—'}</div>
              </div>
              <div style={{ width: 1, height: 30, background: 'var(--line)' }} />
              <div>
                <div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 3 }}>Status</div>
                <Badge tone="green" dot>{selected.status}{selected.isMatured ? ' — Matured' : ''}</Badge>
              </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12 }}>
              <div><div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 3 }}>Principal</div><div style={{ fontFamily: 'JetBrains Mono', fontWeight: 700, fontSize: 15 }}>{money(selected.principalAmount)}</div></div>
              <div><div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 3 }}>Annual Rate</div><div style={{ fontWeight: 700, fontSize: 15 }}>{Number(selected.interestRatePercent).toFixed(2)}%</div></div>
              <div><div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 3 }}>Interest Method</div><div style={{ fontWeight: 700, fontSize: 15 }}>{selected.interestPaymentMethod === 'monthly' ? 'Monthly' : 'At Maturity'}</div></div>
              <div><div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 3 }}>Start Date</div><div style={{ fontWeight: 700, fontSize: 15 }}>{dateOnly(selected.startDate)}</div></div>
              <div><div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 3 }}>Maturity Date</div><div style={{ fontWeight: 700, fontSize: 15 }}>{dateOnly(selected.maturityDate)}</div></div>
              <div><div className="t-2" style={{ fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '.06em', marginBottom: 3 }}>Maturity Amount</div><div style={{ fontFamily: 'JetBrains Mono', fontWeight: 700, fontSize: 15 }}>{money(selected.maturityAmount)}</div></div>
            </div>
          </Card>

          <Card pad={0} style={{ marginBottom: 16 }}>
            <div className="row gap-1" style={{ padding: '10px 14px', borderBottom: '1px solid var(--line)' }}>
              {selected.interestPaymentMethod === 'monthly' && <Btn size="sm" variant={tab === 'schedule' ? 'primary' : 'ghost'} onClick={() => setTab('schedule')}>Interest Schedule</Btn>}
              <Btn size="sm" variant={tab === 'partpayments' ? 'primary' : 'ghost'} onClick={() => setTab('partpayments')}>Part Payments {partPayments.length ? `(${partPayments.length})` : ''}</Btn>
              {selected.withdrawal && <Btn size="sm" variant={tab === 'withdrawal' ? 'primary' : 'ghost'} onClick={() => setTab('withdrawal')}>Withdrawal</Btn>}
            </div>

            {tab === 'schedule' && selected.interestPaymentMethod === 'monthly' && (
              <Table cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: 'Paid At' }]}
                rows={schedules} empty="No interest schedule"
                render={(p: FdInterestPayment) => <>
                  <Td>{p.installmentNo}</Td>
                  <Td>{dateOnly(p.dueDate)}</Td>
                  <Td align="right" mono>{money(p.amount)}</Td>
                  <Td align="center"><Badge tone={p.status === 'Paid' ? 'green' : 'amber'} dot>{p.status}</Badge></Td>
                  <Td>{dateOnly(p.paidAt)}</Td>
                </>} />
            )}

            {tab === 'partpayments' && (
              <Table cols={[{ label: 'Voucher' }, { label: 'Date' }, { label: 'Principal', align: 'right' }, { label: 'Net Paid', align: 'right' }, { label: 'Method' }, { label: '', align: 'right', w: 90 }]}
                rows={partPayments} empty="No part payments recorded"
                render={(pp: FdPartPayment) => <>
                  <Td mono>{pp.voucherNo}</Td>
                  <Td>{dateOnly(pp.paidAt)}</Td>
                  <Td align="right" mono>{money(pp.principalAmount)}</Td>
                  <Td align="right" mono>{money(pp.netAmountPaid)}</Td>
                  <Td>{pp.paymentMethod}</Td>
                  <Td align="right">
                    <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                      <button className="mms-act" title="Print" onClick={() => setDoc({ kind: 'partpayment', partPayment: pp, fd: selected })}><Icon n="print" s={14} /></button>
                      {pp.id === latestPartPaymentId && (
                        <Can user={me} module="Fixed Deposits" action="Delete">
                          <button className="mms-act" title="Void" onClick={() => voidPartPayment(pp)}><Icon n="trash" s={14} /></button>
                        </Can>
                      )}
                    </div>
                  </Td>
                </>} />
            )}

            {tab === 'withdrawal' && selected.withdrawal && (
              <div style={{ padding: 16 }}>
                <div className="col gap-2" style={{ fontSize: 12.5, maxWidth: 360 }}>
                  <div className="row between"><span className="t-2">Voucher No</span><b>{selected.withdrawal.voucherNo}</b></div>
                  <div className="row between"><span className="t-2">Closed At</span><b>{dateOnly(selected.withdrawal.closedAt)}</b></div>
                  <div className="row between"><span className="t-2">Total Paid</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(selected.withdrawal.totalAmount)}</b></div>
                </div>
                <div className="row gap-2" style={{ marginTop: 14 }}>
                  <Btn size="sm" variant="solid" icon="print" onClick={() => setDoc({ kind: 'withdrawal', withdrawal: selected.withdrawal!, fd: selected })}>Print Voucher</Btn>
                  <Can user={me} module="Fixed Deposits" action="Delete">
                    <Btn size="sm" variant="danger" icon="x" onClick={voidWithdrawal}>Void Withdrawal</Btn>
                  </Can>
                </div>
              </div>
            )}
          </Card>
        </>
      )}

      <PayInterestModal payment={payTarget} open={!!payTarget} onClose={() => setPayTarget(null)}
        onPaid={(p) => { const fd = selected!; setPayTarget(null); setDoc({ kind: 'interest', payment: p, fd }); refresh(); reloadSelected() }} />
      <WithdrawModal fd={selected} open={withdrawOpen} onClose={() => setWithdrawOpen(false)}
        onWithdrawn={(w, fd) => { setWithdrawOpen(false); setDoc({ kind: 'withdrawal', withdrawal: w, fd }); refresh(); reloadSelected() }} />
      <PartPaymentModal fd={selected} open={partPaymentOpen} onClose={() => setPartPaymentOpen(false)}
        onPaid={(pp, fd) => { setPartPaymentOpen(false); setDoc({ kind: 'partpayment', partPayment: pp, fd }); refresh(); reloadSelected() }} />

      {!selected && !loadingFd && !loading && fds.length === 0 && (
        <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>No active Fixed Deposits found.</div>
      )}
    </div>
  )
}

export default DepositPaymentScreen
