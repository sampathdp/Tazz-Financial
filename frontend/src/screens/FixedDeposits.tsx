/* Funding Management — Fixed Deposits: create an FD from an available deposit,
   track its interest schedule, renew (with optional capital enhancement) at
   maturity, and withdraw (principal + interest) to close it. Every terminal
   action (create/renew/withdraw/pay-interest) prints a document following the
   same window.print()/.no-print pattern as PaymentReceipt.tsx. */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, BankSelect, SearchSelect, Modal, Table, Td, inputStyle, ErrorBanner , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  fixedDepositsApi, fdDepositsApi, fundingProductsApi, fdInterestPaymentsApi, company,
  type FixedDeposit, type FdDeposit, type FundingProduct, type FdInterestPayment, type FdWithdrawal,
  type FdPaymentMethod, type FixedDepositStatus, type FdInterestMethod, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)
const addMonths = (iso: string, months: number) => {
  const d = new Date(iso)
  const day = d.getDate()
  d.setDate(1)
  d.setMonth(d.getMonth() + months)
  const lastDay = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()
  d.setDate(Math.min(day, lastDay))
  return d.toISOString().slice(0, 10)
}

const METHODS: { value: FdPaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' },
  { value: 'Cheque', label: 'Cheque' },
  { value: 'Bank', label: 'Bank Transfer' },
]

/** Client-side preview only — mirrors App\Support\FdInterestCalculator. The server always computes the authoritative figures. */
function previewInterest(principal: number, annualRate: number, termMonths: number, method: 'monthly' | 'maturity') {
  const monthlyInterest = Math.round(principal * (annualRate / 12 / 100) * 100) / 100
  if (method === 'monthly') {
    return { monthlyInterest, totalInterest: Math.round(monthlyInterest * termMonths * 100) / 100, maturityAmount: principal }
  }
  const totalInterest = Math.round(principal * (annualRate / 100) * (termMonths / 12) * 100) / 100
  return { monthlyInterest: null as number | null, totalInterest, maturityAmount: Math.round((principal + totalInterest) * 100) / 100 }
}

const statusTone: Record<string, 'green' | 'red' | 'amber' | 'neutral' | 'blue'> = { Active: 'green', Closed: 'neutral', Renewed: 'blue' }

type PrintDoc =
  | { kind: 'certificate'; fd: FixedDeposit }
  | { kind: 'renewal'; fd: FixedDeposit; enhancementDeposit: FdDeposit | null }
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
        {doc.kind === 'certificate' && (() => {
          const { fd } = doc
          return <>
            <DocHeader brand={brand} title="FIXED DEPOSIT CERTIFICATE" />
            <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
              <DocRow label="FD No" value={fd.fdNumber} />
              <DocRow label="Customer" value={fd.customer?.fullName || '—'} />
              <DocRow label="NIC No" value={fd.customer?.nicNo || '—'} />
              <DocRow label="Product" value={fd.fundingProduct?.name || '—'} />
            </div>
            <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
              <DocRow label="Principal" value={money(fd.principalAmount)} />
              <DocRow label="Annual Rate" value={`${Number(fd.interestRatePercent).toFixed(2)}%`} />
              <DocRow label="Interest Method" value={fd.interestPaymentMethod === 'monthly' ? 'Monthly Interest' : 'Interest at Maturity'} />
              <DocRow label="Term" value={`${fd.termMonths} months`} />
              <DocRow label="Start Date" value={dateOnly(fd.startDate)} />
              <DocRow label="Maturity Date" value={dateOnly(fd.maturityDate)} />
              {fd.interestPaymentMethod === 'monthly' && <DocRow label="Monthly Interest" value={money(fd.monthlyInterestAmount)} />}
              <DocRow label="Total Interest" value={money(fd.totalInterestAmount)} />
            </div>
            <div className="row between" style={{ fontSize: 15, fontWeight: 700 }}>
              <span>Maturity Amount</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(fd.maturityAmount)}</span>
            </div>
          </>
        })()}

        {doc.kind === 'renewal' && (() => {
          const { fd, enhancementDeposit } = doc
          return <>
            <DocHeader brand={brand} title="FD RENEWAL CERTIFICATE" />
            <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
              <DocRow label="New FD No" value={fd.fdNumber} />
              <DocRow label="Renewed From" value={fd.renewedFrom?.fdNumber || '—'} />
              <DocRow label="Customer" value={fd.customer?.fullName || '—'} />
              <DocRow label="Product" value={fd.fundingProduct?.name || '—'} />
            </div>
            {enhancementDeposit && (
              <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
                <div className="eyebrow" style={{ marginBottom: 4 }}>Capital Enhancement</div>
                <DocRow label="Deposit No" value={enhancementDeposit.depositNumber} />
                <DocRow label="Additional Amount" value={money(enhancementDeposit.amount)} />
                <DocRow label="Payment Method" value={enhancementDeposit.paymentMethod} />
              </div>
            )}
            <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
              <DocRow label="New Principal" value={money(fd.principalAmount)} />
              <DocRow label="Annual Rate" value={`${Number(fd.interestRatePercent).toFixed(2)}%`} />
              <DocRow label="Term" value={`${fd.termMonths} months`} />
              <DocRow label="Start Date" value={dateOnly(fd.startDate)} />
              <DocRow label="Maturity Date" value={dateOnly(fd.maturityDate)} />
              <DocRow label="Total Interest" value={money(fd.totalInterestAmount)} />
            </div>
            <div className="row between" style={{ fontSize: 15, fontWeight: 700 }}>
              <span>Maturity Amount</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(fd.maturityAmount)}</span>
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
          <div>Authorized By: ______________</div>
          <div>Signature: ______________</div>
        </div>
      </Card>
    </div>
  )
}

/* ============================ Create FD modal ============================ */

function CreateFdModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (fd: FixedDeposit) => void }) {
  const notify = useNotify()
  const [deposits, setDeposits] = useState<FdDeposit[]>([])
  const [products, setProducts] = useState<FundingProduct[]>([])
  const [depositLabel, setDepositLabel] = useState('')
  const [depositId, setDepositId] = useState<number | undefined>()
  const [productId, setProductId] = useState<number | undefined>()
  const [term, setTerm] = useState<number | '' | 'custom'>('')
  const [customTerm, setCustomTerm] = useState('')
  const [startDate, setStartDate] = useState(todayIso())
  const [interestMethod, setInterestMethod] = useState<FdInterestMethod | ''>('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open) return
    fdDepositsApi.list({ status: 'Available' }).then(setDeposits).catch(() => setDeposits([]))
    fundingProductsApi.list().then((d) => setProducts(d.filter((p) => (p.status || 'Active') === 'Active'))).catch(() => setProducts([]))
    setDepositLabel(''); setDepositId(undefined); setProductId(undefined); setTerm(''); setCustomTerm(''); setStartDate(todayIso()); setInterestMethod(''); setErr('')
  }, [open])

  const deposit = deposits.find((d) => d.id === depositId)
  const product = products.find((p) => p.id === productId)
  // Reset to the product's default whenever the selected product changes.
  useEffect(() => { setInterestMethod(product?.interestMethod || '') }, [product?.id])
  const effectiveInterestMethod = interestMethod || product?.interestMethod
  const effectiveTerm = term === 'custom' ? parseInt(customTerm, 10) : (term || undefined)
  const preview = deposit && product && effectiveTerm && effectiveInterestMethod
    ? previewInterest(Number(deposit.amount), Number(product.annualRatePercent), effectiveTerm, effectiveInterestMethod)
    : null
  const maturityPreview = startDate && effectiveTerm ? addMonths(startDate, effectiveTerm) : null

  const save = async () => {
    if (!depositId) { setErr('Select an available deposit.'); return }
    if (!productId) { setErr('Select a funding product.'); return }
    if (!effectiveTerm || effectiveTerm <= 0) { setErr('Select or enter a valid term.'); return }
    setBusy(true); setErr('')
    try {
      const fd = await fixedDepositsApi.create({ depositId, fundingProductId: productId, termMonths: effectiveTerm, startDate, interestPaymentMethod: interestMethod || undefined })
      onCreated(fd)
      notify.success('Fixed Deposit created', fd.fdNumber)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not create the Fixed Deposit.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={620} title="Create Fixed Deposit"
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Creating...' : 'Create & Print Certificate'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div className="col gap-3">
        <Field label="Available Deposit">
          <SearchSelect
            value={depositLabel}
            onChange={(name, opt: FdDeposit | undefined) => { setDepositId(opt?.id); setDepositLabel(name) }}
            options={deposits}
            getLabel={(d: FdDeposit) => `${d.depositNumber} — ${d.customer?.fullName || ''} — ${money(d.amount)}`}
            getSub={(d: FdDeposit) => dateOnly(d.depositDate)}
            placeholder={deposits.length ? 'Search available deposits…' : 'No available deposits — record one first'}
          />
        </Field>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Funding Product"><Select value={productId ?? ''} onChange={(e) => { setProductId(Number(e.target.value) || undefined); setTerm('') }}>
            <option value="">—</option>
            {products.map((p) => <option key={p.id} value={p.id}>{p.name} ({Number(p.annualRatePercent).toFixed(2)}%)</option>)}
          </Select></Field>
          <Field label="Start Date"><DateInput value={startDate} onChange={setStartDate} /></Field>
        </div>
        {product && (
          <Field label="Term">
            <div className="row gap-2 wrap">
              {product.allowedTermsMonths.map((t) => (
                <Btn key={t} size="sm" variant={term === t ? 'primary' : 'ghost'} onClick={() => setTerm(t)}>{t} months</Btn>
              ))}
              {product.allowCustomTerm && <Btn size="sm" variant={term === 'custom' ? 'primary' : 'ghost'} onClick={() => setTerm('custom')}>Custom</Btn>}
            </div>
            {term === 'custom' && <Input type="number" style={{ marginTop: 8 }} value={customTerm} onChange={(e) => setCustomTerm(e.target.value)} placeholder="Months" />}
          </Field>
        )}
        {product && product.allowMethodChoice && (
          <Field label="Interest Method">
            <div className="row gap-2 wrap">
              <Btn size="sm" variant={effectiveInterestMethod === 'monthly' ? 'primary' : 'ghost'} onClick={() => setInterestMethod('monthly')}>Monthly Interest</Btn>
              <Btn size="sm" variant={effectiveInterestMethod === 'maturity' ? 'primary' : 'ghost'} onClick={() => setInterestMethod('maturity')}>Interest at Maturity</Btn>
            </div>
          </Field>
        )}
        {deposit && product && (
          <div style={{ background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: 14 }}>
            {deposit.amount < product.minDepositAmount || (product.maxDepositAmount && deposit.amount > product.maxDepositAmount) ? (
              <div style={{ color: 'var(--bad)', fontSize: 12.5 }}>This deposit's amount is outside {product.name}'s allowed range ({money(product.minDepositAmount)}–{product.maxDepositAmount ? money(product.maxDepositAmount) : '∞'}).</div>
            ) : preview ? (
              <div className="col gap-2" style={{ fontSize: 12.5 }}>
                <div className="eyebrow" style={{ marginBottom: 2 }}>Preview (server computes the final figures)</div>
                <div className="row between"><span className="t-2">Principal</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(deposit.amount)}</b></div>
                {preview.monthlyInterest !== null && <div className="row between"><span className="t-2">Monthly Interest</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.monthlyInterest)}</b></div>}
                <div className="row between"><span className="t-2">Total Interest</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.totalInterest)}</b></div>
                <div className="row between"><span className="t-2">Maturity Date</span><b>{maturityPreview}</b></div>
                <div className="row between" style={{ fontWeight: 700 }}><span>Maturity Amount</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.maturityAmount)}</b></div>
              </div>
            ) : <div className="t-2" style={{ fontSize: 12.5 }}>Pick a term to preview the interest figures.</div>}
          </div>
        )}
      </div>
    </Modal>
  )
}

/* ============================ Renew modal ============================ */

function RenewModal({ fd, open, onClose, onRenewed }: { fd: FixedDeposit | null; open: boolean; onClose: () => void; onRenewed: (fd: FixedDeposit, enhancementDeposit: FdDeposit | null) => void }) {
  const notify = useNotify()
  const [term, setTerm] = useState<number | '' | 'custom'>('')
  const [customTerm, setCustomTerm] = useState('')
  const [notes, setNotes] = useState('')
  const [enhance, setEnhance] = useState(false)
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<FdPaymentMethod>('Cash')
  const [refNo, setRefNo] = useState('')
  const [bankId, setBankId] = useState<number | undefined>(undefined)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open || !fd) return
    setTerm(fd.termMonths); setCustomTerm(''); setNotes(''); setEnhance(false)
    setAmount(''); setMethod('Cash'); setRefNo(''); setBankId(undefined); setErr('')
  }, [open, fd])

  if (!fd) return null
  const product = fd.fundingProduct
  const effectiveTerm = term === 'custom' ? parseInt(customTerm, 10) : (term || undefined)
  const newPrincipal = Number(fd.principalAmount) + (enhance ? Number(amount || 0) : 0)
  const preview = product && effectiveTerm ? previewInterest(newPrincipal, Number(fd.interestRatePercent), effectiveTerm, fd.interestPaymentMethod) : null

  const save = async () => {
    if (!effectiveTerm || effectiveTerm <= 0) { setErr('Select or enter a valid term.'); return }
    if (enhance && (!amount || Number(amount) <= 0)) { setErr('Enter the additional deposit amount.'); return }
    if (enhance && method !== 'Cash' && !bankId) { setErr('Bank name is required for Cheque/Bank Transfer.'); return }
    setBusy(true); setErr('')
    try {
      const result = await fixedDepositsApi.renew(fd.id, {
        termMonths: effectiveTerm, notes: notes || undefined,
        enhancement: enhance ? { amount, paymentMethod: method, referenceNo: refNo || undefined, bankId: method !== 'Cash' ? bankId : undefined } : undefined,
      })
      onRenewed(result.fixedDeposit, result.enhancementDeposit)
      notify.success('Fixed Deposit renewed', result.fixedDeposit.fdNumber)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not renew this Fixed Deposit.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={600} title={`Renew ${fd.fdNumber}`}
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Renewing...' : 'Renew & Print Certificate'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div className="col gap-3">
        <Field label="New Term">
          <div className="row gap-2 wrap">
            {(product?.allowedTermsMonths || []).map((t) => (
              <Btn key={t} size="sm" variant={term === t ? 'primary' : 'ghost'} onClick={() => setTerm(t)}>{t} months</Btn>
            ))}
            {product?.allowCustomTerm && <Btn size="sm" variant={term === 'custom' ? 'primary' : 'ghost'} onClick={() => setTerm('custom')}>Custom</Btn>}
          </div>
          {term === 'custom' && <Input type="number" style={{ marginTop: 8 }} value={customTerm} onChange={(e) => setCustomTerm(e.target.value)} placeholder="Months" />}
        </Field>

        <label className="row gap-2" style={{ fontSize: 12.5 }}>
          <input type="checkbox" checked={enhance} onChange={(e) => setEnhance(e.target.checked)} /> Add capital (top up the principal with a new deposit)
        </label>
        {enhance && (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Additional Amount"><Input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
            <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as FdPaymentMethod)}>
              {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
            <Field label="Reference No"><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>
            {method !== 'Cash' && <Field label="Bank Name"><BankSelect value={bankId} onChange={setBankId} /></Field>}
          </div>
        )}
        <Field label="Notes" full><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>

        {preview && (
          <div style={{ background: 'var(--bg-3)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: 14 }}>
            <div className="eyebrow" style={{ marginBottom: 6 }}>Preview</div>
            <div className="col gap-2" style={{ fontSize: 12.5 }}>
              <div className="row between"><span className="t-2">New Principal</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(newPrincipal)}</b></div>
              {preview.monthlyInterest !== null && <div className="row between"><span className="t-2">Monthly Interest</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.monthlyInterest)}</b></div>}
              <div className="row between" style={{ fontWeight: 700 }}><span>Maturity Amount</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(preview.maturityAmount)}</b></div>
            </div>
          </div>
        )}
      </div>
    </Modal>
  )
}

/* ============================ Withdraw modal ============================ */

function WithdrawModal({ fd, open, onClose, onWithdrawn }: { fd: FixedDeposit | null; open: boolean; onClose: () => void; onWithdrawn: (withdrawal: FdWithdrawal, fd: FixedDeposit) => void }) {
  const notify = useNotify()
  const [method, setMethod] = useState<FdPaymentMethod>('Cash')
  const [refNo, setRefNo] = useState('')
  const [notes, setNotes] = useState('')
  const [closedAt, setClosedAt] = useState(todayIso())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { if (open) { setMethod('Cash'); setRefNo(''); setNotes(''); setClosedAt(todayIso()); setErr('') } }, [open])
  if (!fd) return null

  const interestPayable = fd.interestPaymentMethod === 'maturity' ? Number(fd.totalInterestAmount) : 0
  const total = Number(fd.principalAmount) + interestPayable

  const save = async () => {
    setBusy(true); setErr('')
    try {
      const result = await fixedDepositsApi.withdraw(fd.id, { paymentMethod: method, referenceNo: refNo || undefined, notes: notes || undefined, closedAt })
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
          <Field label="Closed Date"><DateInput value={closedAt} onChange={setClosedAt} /></Field>
          <Field label="Reference No"><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>
        </div>
        <Field label="Notes" full><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>
    </Modal>
  )
}

/* ============================ Pay interest modal ============================ */

function PayInterestModal({ payment, open, onClose, onPaid }: { payment: FdInterestPayment | null; open: boolean; onClose: () => void; onPaid: () => void }) {
  const notify = useNotify()
  const [method, setMethod] = useState<FdPaymentMethod>('Cash')
  const [refNo, setRefNo] = useState('')
  const [paidAt, setPaidAt] = useState(todayIso())
  const [backDated, setBackDated] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { if (open) { setMethod('Cash'); setRefNo(''); setPaidAt(todayIso()); setBackDated(false); setErr('') } }, [open])
  if (!payment) return null

  const save = async () => {
    setBusy(true); setErr('')
    try {
      await fdInterestPaymentsApi.pay(payment.id, { paymentMethod: method, referenceNo: refNo || undefined, paidAt })
      onPaid()
      notify.success('Interest payment recorded')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not record this interest payment.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={460} title={`Pay Interest — Installment #${payment.installmentNo}`} sub={`Due ${dateOnly(payment.dueDate)} — Amount ${money(payment.amount)}`}
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Recording...' : 'Record Payment'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as FdPaymentMethod)}>
          {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
        </Select></Field>
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

/* ============================ Detail view ============================ */

function FDDetail({ fd, me, onBack, onChanged, onPrint }: {
  fd: FixedDeposit; me?: AuthUser | null; onBack: () => void
  onChanged: () => void
  onPrint: (doc: PrintDoc) => void
}) {
  const notify = useNotify()
  const [renewOpen, setRenewOpen] = useState(false)
  const [withdrawOpen, setWithdrawOpen] = useState(false)
  const [payTarget, setPayTarget] = useState<FdInterestPayment | null>(null)

  const canRenewOrWithdraw = fd.status === 'Active' && fd.isMatured && !fd.isPledged
  const hasPendingInterest = fd.interestPaymentMethod === 'monthly' && (fd.interestPayments || []).some((p) => p.status === 'Pending')

  const voidInterestPayment = async (p: FdInterestPayment) => {
    const ok = await notify.confirm({ title: `Void interest payment #${p.installmentNo}?`, msg: 'Reverses its GL/Cashbook entries and puts it back to Pending.', danger: true, okText: 'Void' })
    if (!ok) return
    try { await fdInterestPaymentsApi.void(p.id); notify.success('Payment voided'); onChanged() }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  const voidWithdrawal = async () => {
    const ok = await notify.confirm({ title: `Void the withdrawal on ${fd.fdNumber}?`, msg: 'Reverses its GL/Cashbook entries and reopens this FD as Active.', danger: true, okText: 'Void' })
    if (!ok) return
    try { await fixedDepositsApi.voidWithdrawal(fd.id); notify.success('Withdrawal voided'); onChanged() }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  return (
    <div>
      <div className="row gap-2" style={{ marginBottom: 16 }}>
        <Btn variant="solid" icon="chev" onClick={onBack}>Back to List</Btn>
        <Btn variant="solid" icon="print" onClick={() => onPrint({ kind: 'certificate', fd })}>Print Certificate</Btn>
        {canRenewOrWithdraw && (
          <Can user={me} module="Fixed Deposits" action="Edit">
            <Btn variant="solid" icon="swap" onClick={() => setRenewOpen(true)}>Renew</Btn>
            <Btn variant="primary" icon="download" onClick={() => setWithdrawOpen(true)} disabled={hasPendingInterest} title={hasPendingInterest ? 'Settle all due monthly interest first' : undefined}>Withdraw</Btn>
          </Can>
        )}
      </div>

      <Card style={{ marginBottom: 16 }}>
        <div className="row between wrap gap-2" style={{ marginBottom: 10 }}>
          <div className="eyebrow">{fd.fdNumber}</div>
          <div className="row gap-2">
            <Badge tone={statusTone[fd.status] || 'neutral'} dot>{fd.status}</Badge>
            {fd.isMatured && <Badge tone="amber" dot>Matured</Badge>}
            {fd.isPledged && <Badge tone="red" dot>Pledged — {Number(fd.lienAmount).toLocaleString()} lien</Badge>}
          </div>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5 }}>
          <div><span className="t-2">Customer: </span><b>{fd.customer?.fullName}</b></div>
          <div><span className="t-2">NIC: </span><b>{fd.customer?.nicNo || '—'}</b></div>
          <div><span className="t-2">Product: </span><b>{fd.fundingProduct?.name}</b></div>
          <div><span className="t-2">Interest Method: </span><b>{fd.interestPaymentMethod === 'monthly' ? 'Monthly Interest' : 'Interest at Maturity'}</b></div>
          <div><span className="t-2">Principal: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(fd.principalAmount)}</b></div>
          <div><span className="t-2">Annual Rate: </span><b>{Number(fd.interestRatePercent).toFixed(2)}%</b></div>
          <div><span className="t-2">Term: </span><b>{fd.termMonths} months</b></div>
          <div><span className="t-2">Start Date: </span><b>{dateOnly(fd.startDate)}</b></div>
          <div><span className="t-2">Maturity Date: </span><b>{dateOnly(fd.maturityDate)}</b></div>
          <div><span className="t-2">Maturity Amount: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(fd.maturityAmount)}</b></div>
          {fd.renewedFrom && <div><span className="t-2">Renewed From: </span><b>{fd.renewedFrom.fdNumber}</b></div>}
          {fd.renewedTo && <div><span className="t-2">Renewed To: </span><b>{fd.renewedTo.fdNumber}</b></div>}
        </div>
      </Card>

      {fd.interestPaymentMethod === 'monthly' && (
        <Card pad={0} style={{ marginBottom: 16 }}>
          <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Monthly Interest Schedule</div>
          <Table cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: 'Paid At' }, { label: '', align: 'right', w: 90 }]}
            rows={fd.interestPayments || []} empty="No interest schedule"
            render={(p: FdInterestPayment) => <>
              <Td>{p.installmentNo}</Td>
              <Td>{dateOnly(p.dueDate)}</Td>
              <Td align="right" mono>{money(p.amount)}</Td>
              <Td align="center"><Badge tone={p.status === 'Paid' ? 'green' : 'amber'} dot>{p.status}</Badge></Td>
              <Td>{dateOnly(p.paidAt)}</Td>
              <Td align="right">
                {p.status === 'Pending' && fd.status === 'Active' && (
                  <Can user={me} module="Fixed Deposits" action="Edit">
                    {new Date().toISOString().slice(0, 10) >= p.dueDate.slice(0, 10) ? (
                      <Btn size="sm" variant="primary" icon="check" onClick={() => setPayTarget(p)}>Pay</Btn>
                    ) : (
                      <span className="t-2" style={{ fontSize: 11.5 }}>Not due yet</span>
                    )}
                  </Can>
                )}
                {p.status === 'Paid' && (
                  <Can user={me} module="Fixed Deposits" action="Delete">
                    <button className="mms-act" title="Void" onClick={() => voidInterestPayment(p)}><Icon n="trash" s={14} /></button>
                  </Can>
                )}
              </Td>
            </>} />
        </Card>
      )}

      <Card pad={0} style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ padding: '14px 16px 0' }}>Deposits Funding This FD</div>
        <Table cols={[{ label: 'Deposit No' }, { label: 'Date' }, { label: 'Method' }, { label: 'Amount', align: 'right' }]}
          rows={fd.deposits || []} empty="No deposits on record"
          render={(d: FdDeposit) => <>
            <Td mono>{d.depositNumber}</Td>
            <Td>{dateOnly(d.depositDate)}</Td>
            <Td>{d.paymentMethod}</Td>
            <Td align="right" mono>{money(d.amount)}</Td>
          </>} />
      </Card>

      {fd.withdrawal && (
        <Card style={{ marginBottom: 16 }}>
          <div className="eyebrow" style={{ marginBottom: 10 }}>Withdrawal</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '10px 20px', fontSize: 12.5 }}>
            <div><span className="t-2">Voucher No: </span><b>{fd.withdrawal.voucherNo}</b></div>
            <div><span className="t-2">Closed At: </span><b>{dateOnly(fd.withdrawal.closedAt)}</b></div>
            <div><span className="t-2">Total Paid: </span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(fd.withdrawal.totalAmount)}</b></div>
          </div>
          <div className="row gap-2" style={{ marginTop: 10 }}>
            <Btn size="sm" variant="solid" icon="print" onClick={() => onPrint({ kind: 'withdrawal', withdrawal: fd.withdrawal!, fd })}>Print Voucher</Btn>
            <Can user={me} module="Fixed Deposits" action="Delete">
              <Btn size="sm" variant="danger" icon="x" onClick={voidWithdrawal}>Void Withdrawal</Btn>
            </Can>
          </div>
        </Card>
      )}

      <RenewModal fd={fd} open={renewOpen} onClose={() => setRenewOpen(false)} onRenewed={(newFd, enh) => { setRenewOpen(false); onPrint({ kind: 'renewal', fd: newFd, enhancementDeposit: enh }); onChanged() }} />
      <WithdrawModal fd={fd} open={withdrawOpen} onClose={() => setWithdrawOpen(false)} onWithdrawn={(w, newFd) => { setWithdrawOpen(false); onPrint({ kind: 'withdrawal', withdrawal: w, fd: newFd }); onChanged() }} />
      <PayInterestModal payment={payTarget} open={!!payTarget} onClose={() => setPayTarget(null)} onPaid={() => { setPayTarget(null); onChanged() }} />
    </div>
  )
}

/* ============================ Main screen ============================ */

export function FixedDepositsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [rows, setRows] = useState<FixedDeposit[]>([])
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<'All' | 'Active' | 'Matured' | 'Closed' | 'Renewed'>('All')
  const [createOpen, setCreateOpen] = useState(false)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [selected, setSelected] = useState<FixedDeposit | null>(null)
  const [loadingSelected, setLoadingSelected] = useState(false)
  const [printDoc, setPrintDoc] = useState<PrintDoc | null>(null)
  const [brand, setBrand] = useState({ name: '', tagline: '' })

  const refresh = () => {
    setLoading(true)
    const params = tab === 'Matured' ? { matured: true } : tab === 'All' ? undefined : { status: tab as FixedDepositStatus }
    fixedDepositsApi.list(params).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [tab]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {}) }, [])

  const openDetail = async (id: number) => {
    setSelectedId(id); setLoadingSelected(true)
    try { setSelected(await fixedDepositsApi.get(id)) } finally { setLoadingSelected(false) }
  }
  const reloadSelected = async () => {
    if (!selectedId) return
    setSelected(await fixedDepositsApi.get(selectedId))
    refresh()
  }
  const backToList = () => { setSelectedId(null); setSelected(null); refresh() }

  if (printDoc) return <PrintView doc={printDoc} brand={brand} onClose={() => { setPrintDoc(null); if (selectedId) reloadSelected(); else refresh() }} />

  if (selectedId) {
    if (loadingSelected || !selected) return <LoadingBlock />
    return <FDDetail fd={selected} me={me} onBack={backToList} onChanged={reloadSelected} onPrint={setPrintDoc} />
  }

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Fixed Deposits" icon="coins" sub={`${rows.length} Fixed Deposits`}
        actions={<Can user={me} module="Fixed Deposits" action="Create"><Btn variant="primary" icon="plus" onClick={() => setCreateOpen(true)}>Create Fixed Deposit</Btn></Can>} />

      <Card pad={0}>
        <div className="row gap-1" style={{ padding: '14px 16px 0' }}>
          {(['All', 'Active', 'Matured', 'Closed', 'Renewed'] as const).map((t) => (
            <Btn key={t} size="sm" variant="ghost" active={tab === t} onClick={() => setTab(t)}>{t}</Btn>
          ))}
        </div>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'FD No' }, { label: 'Customer' }, { label: 'Product' }, { label: 'Principal', align: 'right' }, { label: 'Rate', align: 'right' }, { label: 'Maturity Date' }, { label: 'Status', align: 'center' }]}
          rows={rows} onRow={(r: FixedDeposit) => openDetail(r.id)}
          render={(fd: FixedDeposit) => <>
            <Td mono>{fd.fdNumber}</Td>
            <Td>{fd.customer?.fullName || '—'}</Td>
            <Td>{fd.fundingProduct?.name || '—'}</Td>
            <Td align="right" mono>{money(fd.principalAmount)}</Td>
            <Td align="right" mono>{Number(fd.interestRatePercent).toFixed(2)}%</Td>
            <Td>{dateOnly(fd.maturityDate)}</Td>
            <Td align="center"><div className="row gap-1" style={{ justifyContent: 'center' }}>
              <Badge tone={statusTone[fd.status] || 'neutral'} dot>{fd.status}</Badge>
              {fd.status === 'Active' && fd.isMatured && <Badge tone="amber">Matured</Badge>}
              {fd.isPledged && <Badge tone="red">Pledged</Badge>}
            </div></Td>
          </>} />}
      </Card>

      <CreateFdModal open={createOpen} onClose={() => setCreateOpen(false)} onCreated={(fd) => { setCreateOpen(false); setPrintDoc({ kind: 'certificate', fd }); refresh() }} />
    </div>
  )
}

export default FixedDepositsScreen
