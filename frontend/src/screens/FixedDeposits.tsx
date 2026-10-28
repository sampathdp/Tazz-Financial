/* Funding Management — Fixed Deposits: create an FD from an available deposit,
   track its interest schedule, renew (with optional capital enhancement) at
   maturity, and withdraw (principal + interest) to close it. Every terminal
   action (create/renew/withdraw/pay-interest) prints a document following the
   same window.print()/.no-print pattern as PaymentReceipt.tsx. */
import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, BankSelect, SearchSelect, Modal, Table, Td, inputStyle, ErrorBanner , LoadingBlock , BankAccountSelect } from '../components/ui'
import { Icon } from '../components/Icon'
import { DetailSummary } from '../components/DetailSummary'
import {
  fixedDepositsApi, fdDepositsApi, fdCommissionsApi, fundingProductsApi, fdInterestPaymentsApi, company,
  type FixedDeposit, type FdDeposit, type FundingProduct, type FdInterestPayment, type FdWithdrawal,
  type FdPaymentMethod, type FixedDepositStatus, type FdInterestMethod, type AuthUser,
  type FdAutoRenewMode, type FdInterestTreatment, type FdWithdrawalPreview, type FdCommission,
} from '../api'
import {
  CommissionFields, JointHolderFields, NomineeFields, PayFdCommissionModal,
  noCommission, commissionBody, jointHoldersBody, nomineesBody,
  type CommissionState, type JointHolderRow, type NomineeRow,
} from '../components/FdExtras'
import { InterestVoucherPage } from './FdInterestVoucher'
import { Can } from '../lib/permissions'
import { AdminEditButton } from '../components/AdminEditButton'
import { useNotify } from '../components/Notify'
import { CustomerBankDetailPicker } from '../components/CustomerBankDetailPicker'
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
  | { kind: 'interest'; payment: FdInterestPayment }

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
  if (doc.kind === 'interest') return <InterestVoucherPage payment={doc.payment} brand={brand} onClose={onClose} />
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
              {Number(withdrawal.postMaturityInterest) > 0 && <DocRow label={`  of which post-maturity (${withdrawal.postMaturityDays} days @ ${Number(withdrawal.postMaturityRatePercent ?? 0).toFixed(2)}%)`} value={money(withdrawal.postMaturityInterest)} />}
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

/* ============================ Auto-renew fields ============================ */

type AutoRenewState = { on: boolean; mode: FdAutoRenewMode; term: number | '' }
const noAutoRenew: AutoRenewState = { on: false, mode: 'principal_only', term: '' }
const autoRenewFrom = (fd: FixedDeposit): AutoRenewState => ({ on: !!fd.autoRenew, mode: fd.autoRenewMode || 'principal_only', term: fd.autoRenewTermMonths || '' })
const autoRenewBody = (a: AutoRenewState) => ({ autoRenew: a.on, autoRenewMode: a.on ? a.mode : undefined, autoRenewTermMonths: a.on && a.term ? a.term : undefined })
const AUTO_RENEW_MODES: { value: FdAutoRenewMode; label: string }[] = [
  { value: 'principal_only', label: 'Renew principal only — interest is paid out' },
  { value: 'principal_and_interest', label: 'Renew principal + interest (compound)' },
]

/** Customer's standing instruction for what happens when the FD matures. */
function AutoRenewFields({ value, onChange, terms, interestMethod }: { value: AutoRenewState; onChange: (v: AutoRenewState) => void; terms: number[]; interestMethod?: FdInterestMethod }) {
  return (
    <div className="col gap-2">
      <label className="row gap-2" style={{ fontSize: 12.5 }}>
        <input type="checkbox" checked={value.on} onChange={(e) => onChange({ ...value, on: e.target.checked })} /> Auto-renew at maturity
      </label>
      {value.on && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          {interestMethod === 'maturity' ? (
            <Field label="On renewal"><Select value={value.mode} onChange={(e) => onChange({ ...value, mode: e.target.value as FdAutoRenewMode })}>
              {AUTO_RENEW_MODES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
          ) : (
            <div className="t-2" style={{ fontSize: 11.5, alignSelf: 'end' }}>Monthly interest is already paid out as it falls due — only the principal renews.</div>
          )}
          <Field label="Renewal Term"><Select value={value.term} onChange={(e) => onChange({ ...value, term: e.target.value ? Number(e.target.value) : '' })}>
            <option value="">Same term as this FD</option>
            {terms.map((t) => <option key={t} value={t}>{t} months</option>)}
          </Select></Field>
        </div>
      )}
    </div>
  )
}

/* ============================ Create FD modal ============================ */

function CreateFdModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (fd: FixedDeposit) => void }) {
  const notify = useNotify()
  const [deposits, setDeposits] = useState<FdDeposit[]>([])
  const [products, setProducts] = useState<FundingProduct[]>([])
  const [depositLabel, setDepositLabel] = useState('')
  const [bankDetailId, setBankDetailId] = useState<number | undefined>(undefined)
  const [depositId, setDepositId] = useState<number | undefined>()
  const [productId, setProductId] = useState<number | undefined>()
  const [term, setTerm] = useState<number | '' | 'custom'>('')
  const [customTerm, setCustomTerm] = useState('')
  const [startDate, setStartDate] = useState(todayIso())
  const [interestMethod, setInterestMethod] = useState<FdInterestMethod | ''>('')
  const [autoRenew, setAutoRenew] = useState<AutoRenewState>(noAutoRenew)
  // Unticked = the payout account is decided later (at withdrawal / interest payout).
  const [requireAccount, setRequireAccount] = useState(true)
  const [rate, setRate] = useState('')
  const [commission, setCommission] = useState<CommissionState>(noCommission)
  const [jointRows, setJointRows] = useState<JointHolderRow[]>([])
  const [nomineeRows, setNomineeRows] = useState<NomineeRow[]>([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open) return
    setAutoRenew(noAutoRenew); setRequireAccount(true); setRate(''); setCommission(noCommission); setJointRows([]); setNomineeRows([])
    fdDepositsApi.list({ status: 'Available' }).then(setDeposits).catch(() => setDeposits([]))
    fundingProductsApi.list().then((d) => setProducts(d.filter((p) => (p.status || 'Active') === 'Active'))).catch(() => setProducts([]))
    setDepositLabel(''); setDepositId(undefined); setBankDetailId(undefined); setProductId(undefined); setTerm(''); setCustomTerm(''); setStartDate(todayIso()); setInterestMethod(''); setErr('')
  }, [open])

  const deposit = deposits.find((d) => d.id === depositId)
  const product = products.find((p) => p.id === productId)
  // Reset to the product's default whenever the selected product changes.
  useEffect(() => { setInterestMethod(product?.interestMethod || ''); setRate(product ? String(Number(product.annualRatePercent)) : '') }, [product?.id])
  const effectiveInterestMethod = interestMethod || product?.interestMethod
  const effectiveTerm = term === 'custom' ? parseInt(customTerm, 10) : (term || undefined)
  const preview = deposit && product && effectiveTerm && effectiveInterestMethod
    ? previewInterest(Number(deposit.amount), rate !== '' ? Number(rate) : Number(product.annualRatePercent), effectiveTerm, effectiveInterestMethod)
    : null
  const maturityPreview = startDate && effectiveTerm ? addMonths(startDate, effectiveTerm) : null

  const save = async () => {
    if (!depositId) { setErr('Select an available deposit.'); return }
    if (requireAccount && !bankDetailId) { setErr("Select or add the customer's payment bank account, or untick 'Payout account required now'."); return }
    if (!productId) { setErr('Select a funding product.'); return }
    if (!effectiveTerm || effectiveTerm <= 0) { setErr('Select or enter a valid term.'); return }
    if (rate !== '' && (Number(rate) < 0 || Number(rate) > 100)) { setErr('Interest rate must be between 0 and 100.'); return }
    const com = commissionBody(commission); if (com.error) { setErr(com.error); return }
    const nom = nomineesBody(nomineeRows); if (nom.error) { setErr(nom.error); return }
    setBusy(true); setErr('')
    try {
      const fd = await fixedDepositsApi.create({
        depositId, customerBankDetailId: requireAccount ? bankDetailId : undefined, fundingProductId: productId, termMonths: effectiveTerm, startDate,
        interestRatePercent: rate !== '' ? rate : undefined, interestPaymentMethod: interestMethod || undefined,
        jointHolders: jointHoldersBody(jointRows), nominees: nom.body, commission: com.body, ...autoRenewBody(autoRenew),
      })
      onCreated(fd)
      notify.success('Fixed Deposit created', fd.fdNumber)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not create the Fixed Deposit.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={680} title="Create Fixed Deposit"
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Creating...' : 'Create & Print Certificate'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <div className="col gap-3">
        <Field label="Available Deposit">
          <SearchSelect
            value={depositLabel}
            onChange={(name, opt: FdDeposit | undefined) => { setDepositId(opt?.id); setDepositLabel(name); setBankDetailId(undefined); setJointRows([]) }}
            options={deposits}
            getLabel={(d: FdDeposit) => `${d.depositNumber} — ${d.customer?.fullName || ''} — ${money(d.amount)}`}
            getSub={(d: FdDeposit) => dateOnly(d.depositDate)}
            placeholder={deposits.length ? 'Search available deposits…' : 'No available deposits — record one first'}
          />
        </Field>
        <label className="row gap-2" style={{ fontSize: 12.5 }}>
          <input type="checkbox" checked={requireAccount} onChange={(e) => setRequireAccount(e.target.checked)} /> Payout account required now (untick to choose it later, at withdrawal)
        </label>
        {requireAccount && (
          <Field label="Payment Bank Account (interest & maturity payments go here)">
            <CustomerBankDetailPicker customerId={deposit?.customerId} value={bankDetailId} onChange={setBankDetailId} />
          </Field>
        )}
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
        {product && (
          <Field label={`Customer Interest Rate % (product rate ${Number(product.annualRatePercent).toFixed(2)}%)`}>
            <Input type="number" step="0.001" value={rate} onChange={(e) => setRate(e.target.value)} />
          </Field>
        )}
        {product && <AutoRenewFields value={autoRenew} onChange={setAutoRenew} terms={product.allowedTermsMonths} interestMethod={effectiveInterestMethod || undefined} />}
        <JointHolderFields rows={jointRows} onChange={setJointRows} primaryCustomerId={deposit?.customerId} />
        <NomineeFields rows={nomineeRows} onChange={setNomineeRows} />
        <CommissionFields value={commission} onChange={setCommission} principal={deposit ? Number(deposit.amount) : undefined} />
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
  const [bankAccountId, setBankAccountId] = useState<number | undefined>(undefined)
  const [refNo, setRefNo] = useState('')
  const [bankId, setBankId] = useState<number | undefined>(undefined)
  const [treatment, setTreatment] = useState<FdInterestTreatment>('payout')
  const [autoRenew, setAutoRenew] = useState<AutoRenewState>(noAutoRenew)
  const [rate, setRate] = useState('')
  const [commission, setCommission] = useState<CommissionState>(noCommission)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open || !fd) return
    setTreatment('payout'); setAutoRenew(autoRenewFrom(fd)); setCommission(noCommission)
    setRate(String(Number(fd.fundingProduct?.annualRatePercent ?? fd.interestRatePercent)))
    setTerm(fd.termMonths); setCustomTerm(''); setNotes(''); setEnhance(false)
    setAmount(''); setMethod('Cash'); setBankAccountId(undefined); setRefNo(''); setBankId(undefined); setErr('')
  }, [open, fd])

  if (!fd) return null
  const product = fd.fundingProduct
  const effectiveTerm = term === 'custom' ? parseInt(customTerm, 10) : (term || undefined)
  const maturityInterest = fd.interestPaymentMethod === 'maturity' ? Number(fd.totalInterestAmount) : 0
  const capitalised = treatment === 'capitalise' ? maturityInterest : 0
  const newPrincipal = Number(fd.principalAmount) + capitalised + (enhance ? Number(amount || 0) : 0)
  const preview = product && effectiveTerm ? previewInterest(newPrincipal, rate !== '' ? Number(rate) : Number(fd.interestRatePercent), effectiveTerm, fd.interestPaymentMethod) : null

  const save = async () => {
    if (!effectiveTerm || effectiveTerm <= 0) { setErr('Select or enter a valid term.'); return }
    if (enhance && (!amount || Number(amount) <= 0)) { setErr('Enter the additional deposit amount.'); return }
    if (enhance && method !== 'Cash' && !bankId) { setErr('Bank name is required for Cheque/Bank Transfer.'); return }
    if (rate !== '' && (Number(rate) < 0 || Number(rate) > 100)) { setErr('Interest rate must be between 0 and 100.'); return }
    const com = commissionBody(commission); if (com.error) { setErr(com.error); return }
    setBusy(true); setErr('')
    try {
      const result = await fixedDepositsApi.renew(fd.id, {
        termMonths: effectiveTerm, notes: notes || undefined, interestRatePercent: rate !== '' ? rate : undefined, commission: com.body,
        interestTreatment: maturityInterest > 0 ? treatment : undefined,
        ...autoRenewBody(autoRenew),
        enhancement: enhance ? { amount, paymentMethod: method, bankAccountId: method !== 'Cash' ? bankAccountId : undefined, referenceNo: refNo || undefined, bankId: method !== 'Cash' ? bankId : undefined } : undefined,
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

        {maturityInterest > 0 && (
          <Field label={`Interest earned over the old term — ${money(maturityInterest)}`}>
            <Select value={treatment} onChange={(e) => setTreatment(e.target.value as FdInterestTreatment)}>
              <option value="payout">Pay the interest out to the customer (cash)</option>
              <option value="capitalise">Add the interest to the new principal</option>
            </Select>
          </Field>
        )}

        <label className="row gap-2" style={{ fontSize: 12.5 }}>
          <input type="checkbox" checked={enhance} onChange={(e) => setEnhance(e.target.checked)} /> Add capital (top up the principal with a new deposit)
        </label>
        {enhance && (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Additional Amount"><Input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
            <Field label="Payment Method"><Select value={method} onChange={(e) => setMethod(e.target.value as FdPaymentMethod)}>
              {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </Select></Field>
            {method !== 'Cash' && <Field label="Received into — company bank account (optional)"><BankAccountSelect value={bankAccountId} onChange={setBankAccountId} /></Field>}
            <Field label="Reference No"><Input value={refNo} onChange={(e) => setRefNo(e.target.value)} /></Field>
            {method !== 'Cash' && <Field label="Bank Name"><BankSelect value={bankId} onChange={setBankId} /></Field>}
          </div>
        )}
        <Field label="Interest Rate % for the new term"><Input type="number" step="0.001" value={rate} onChange={(e) => setRate(e.target.value)} /></Field>
        <AutoRenewFields value={autoRenew} onChange={setAutoRenew} terms={product?.allowedTermsMonths || []} interestMethod={fd.interestPaymentMethod} />
        <CommissionFields value={commission} onChange={setCommission} principal={newPrincipal} />
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
  const [bankAccountId, setBankAccountId] = useState<number | undefined>(undefined)
  const [refNo, setRefNo] = useState('')
  const [notes, setNotes] = useState('')
  const [closedAt, setClosedAt] = useState(todayIso())
  const [rate, setRate] = useState('')
  const [pmOverride, setPmOverride] = useState<string | null>(null)
  const [pm, setPm] = useState<FdWithdrawalPreview | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { if (open) { setMethod('Cash'); setBankAccountId(undefined); setRefNo(''); setNotes(''); setClosedAt(todayIso()); setRate(''); setPmOverride(null); setPm(null); setErr('') } }, [open])

  // Days held past maturity and the default interest for them (server-computed from the product's post-maturity rate).
  const fdId = fd?.id
  useEffect(() => {
    if (!open || !fdId || !closedAt) return
    let live = true
    setPmOverride(null)
    fixedDepositsApi.withdrawalPreview(fdId, { closedAt, postMaturityRatePercent: rate === '' ? undefined : rate })
      .then((p) => { if (live) setPm(p) }).catch(() => { if (live) setPm(null) })
    return () => { live = false }
  }, [open, fdId, closedAt, rate])

  if (!fd) return null

  const lateDays = pm?.postMaturityDays ?? 0
  const shownRate = rate !== '' ? rate : (pm?.postMaturityRatePercent ?? '')
  const postMaturityInterest = lateDays > 0 ? Number(pmOverride ?? pm?.postMaturityInterest ?? 0) : 0
  const interestPayable = (fd.interestPaymentMethod === 'maturity' ? Number(fd.totalInterestAmount) : 0) + postMaturityInterest
  const total = Number(fd.principalAmount) + interestPayable

  const save = async () => {
    if (method !== 'Cash' && !bankAccountId) { setErr('Choose the company bank account this payment is made from.'); return }
    setBusy(true); setErr('')
    try {
      const result = await fixedDepositsApi.withdraw(fd.id, { paymentMethod: method, bankAccountId: method !== 'Cash' ? bankAccountId : undefined, referenceNo: refNo || undefined, notes: notes || undefined, closedAt, postMaturityRatePercent: lateDays > 0 && rate !== '' ? rate : undefined, postMaturityInterest: lateDays > 0 ? postMaturityInterest : undefined })
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
          {postMaturityInterest > 0 && <div className="row between"><span className="t-2">&nbsp;&nbsp;incl. post-maturity ({lateDays} days)</span><b style={{ fontFamily: 'JetBrains Mono' }}>{money(postMaturityInterest)}</b></div>}
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
        {lateDays > 0 && (
          <div style={{ border: '1px solid var(--line)', borderRadius: 'var(--r-s)', padding: 14 }}>
            <div className="eyebrow" style={{ marginBottom: 8 }}>Held {lateDays} day{lateDays === 1 ? '' : 's'} past maturity ({dateOnly(fd.maturityDate)})</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="Annual Rate (%)"><Input type="number" step="0.01" value={shownRate} onChange={(e) => setRate(e.target.value)} placeholder="0 = no extra interest" /></Field>
              <Field label="Post-maturity Interest"><Input type="number" step="0.01" value={pmOverride ?? (pm?.postMaturityInterest ?? 0)} onChange={(e) => setPmOverride(e.target.value)} /></Field>
            </div>
            <div className="t-2" style={{ fontSize: 11, marginTop: 6 }}>Calculated as principal × rate × days ÷ 365 — edit the amount to match what was agreed with the customer.</div>
          </div>
        )}
        <Field label="Notes" full><textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>
    </Modal>
  )
}

/* ============================ Auto-renew modal ============================ */

function AutoRenewModal({ fd, open, onClose, onSaved }: { fd: FixedDeposit; open: boolean; onClose: () => void; onSaved: () => void }) {
  const notify = useNotify()
  const [value, setValue] = useState<AutoRenewState>(noAutoRenew)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  useEffect(() => { if (open) { setValue(autoRenewFrom(fd)); setErr('') } }, [open, fd])

  const save = async () => {
    setBusy(true); setErr('')
    try {
      await fixedDepositsApi.setAutoRenew(fd.id, autoRenewBody(value) as { autoRenew: boolean })
      notify.success(value.on ? 'Auto-renew enabled' : 'Auto-renew turned off', fd.fdNumber)
      onSaved()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not update auto-renew.'))
    } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} width={520} title={`Auto-renew — ${fd.fdNumber}`}
      footer={<>
        <Btn variant="plain" onClick={onClose}>Cancel</Btn>
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : 'Save'}</Btn>
      </>}>
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />
      <AutoRenewFields value={value} onChange={setValue} terms={fd.fundingProduct?.allowedTermsMonths || []} interestMethod={fd.interestPaymentMethod} />
      <div className="t-2" style={{ fontSize: 11.5, marginTop: 10 }}>The FD is renewed automatically on its maturity date (unless it is pledged to a loan). Matured FDs without this instruction stay open until someone renews or withdraws them.</div>
    </Modal>
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
        <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Recording...' : 'Record Payment'}</Btn>
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

/* ============================ Detail view ============================ */

function FDDetail({ fd, me, onBack, onChanged, onPrint }: {
  fd: FixedDeposit; me?: AuthUser | null; onBack: () => void
  onChanged: () => void
  onPrint: (doc: PrintDoc) => void
}) {
  const notify = useNotify()
  const [renewOpen, setRenewOpen] = useState(false)
  const [withdrawOpen, setWithdrawOpen] = useState(false)
  const [autoRenewOpen, setAutoRenewOpen] = useState(false)
  const [payTarget, setPayTarget] = useState<FdInterestPayment | null>(null)
  const [commissionPay, setCommissionPay] = useState<FdCommission | null>(null)
  const [commissionOpen, setCommissionOpen] = useState(false)
  const [commissionForm, setCommissionForm] = useState<CommissionState>({ ...noCommission, on: true })
  const [commissionErr, setCommissionErr] = useState('')
  const [accountOpen, setAccountOpen] = useState(false)
  const [accountId, setAccountId] = useState<number | undefined>(undefined)

  const canRenewOrWithdraw = fd.status === 'Active' && fd.isMatured && !fd.isPledged
  const hasPendingInterest = fd.interestPaymentMethod === 'monthly' && (fd.interestPayments || []).some((p) => p.status === 'Pending')

  const voidInterestPayment = async (p: FdInterestPayment) => {
    const ok = await notify.confirm({ title: `Void interest payment #${p.installmentNo}?`, msg: 'Reverses its GL/Cashbook entries and puts it back to Pending.', danger: true, okText: 'Void' })
    if (!ok) return
    try { await fdInterestPaymentsApi.void(p.id); notify.success('Payment voided'); onChanged() }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  const printInterestVoucher = async (p: FdInterestPayment) => {
    try { onPrint({ kind: 'interest', payment: await fdInterestPaymentsApi.voucher(p.id) }) }
    catch (ex: any) { notify.error('Could not load voucher', getErrorText(ex)) }
  }

  const addCommission = async () => {
    const com = commissionBody(commissionForm)
    if (com.error || !com.body) { setCommissionErr(com.error || ''); return }
    try { await fdCommissionsApi.create(fd.id, com.body); setCommissionOpen(false); notify.success('Commission added'); onChanged() }
    catch (ex: any) { setCommissionErr(getErrorText(ex, 'Could not add the commission.')) }
  }
  const removeCommission = async (c: FdCommission) => {
    const ok = await notify.confirm({ title: `Delete the commission for ${c.recipientName}?`, msg: 'Only unpaid commissions can be deleted.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await fdCommissionsApi.remove(c.id); notify.success('Commission deleted'); onChanged() }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }
  const voidCommission = async (c: FdCommission) => {
    const ok = await notify.confirm({ title: 'Void this commission payment?', msg: `Reverses ${money(c.paymentAmount)} paid to ${c.recipientName} and restores the till/bank balance.`, danger: true, okText: 'Void' })
    if (!ok) return
    try { await fdCommissionsApi.void(c.id); notify.success('Payment voided'); onChanged() }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }
  const savePayoutAccount = async () => {
    if (!accountId) { notify.error('Select a bank account'); return }
    try { await fixedDepositsApi.setPayoutAccount(fd.id, accountId); setAccountOpen(false); notify.success('Payout account saved'); onChanged() }
    catch (ex: any) { notify.error('Could not save the account', getErrorText(ex)) }
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
        {fd.status === 'Active' && (
          <AdminEditButton user={me} title={`Edit ${fd.fdNumber}`} values={{ ...fd }}
            hint="Principal is fixed by the allocated deposit. Editing is blocked once any interest, part payment or lien exists."
            fields={[
              { key: 'interestRatePercent', label: 'Annual Rate %', type: 'number', step: '0.001' },
              { key: 'termMonths', label: 'Term (months)', type: 'number' },
              { key: 'startDate', label: 'Start Date', type: 'date' },
              { key: 'interestPaymentMethod', label: 'Interest Payment', type: 'select', options: [{ value: 'monthly', label: 'Monthly' }, { value: 'maturity', label: 'At maturity' }] },
            ]}
            onSave={(v) => fixedDepositsApi.adminUpdate(fd.id, v as any)} onSaved={onChanged} />
        )}
        {fd.status === 'Active' && <Can user={me} module="Fixed Deposits" action="Edit"><Btn variant="solid" icon="swap" onClick={() => setAutoRenewOpen(true)}>Auto-renew</Btn></Can>}
        {canRenewOrWithdraw && (
          <Can user={me} module="Fixed Deposits" action="Edit">
            <Btn variant="solid" icon="swap" onClick={() => setRenewOpen(true)}>Renew</Btn>
            <Btn variant="primary" icon="download" onClick={() => setWithdrawOpen(true)} disabled={hasPendingInterest} title={hasPendingInterest ? 'Settle all due monthly interest first' : undefined}>Withdraw</Btn>
          </Can>
        )}
      </div>

      <div style={{ marginBottom: 24 }}>
        <DetailSummary
          code={fd.fdNumber} title={fd.customer?.fullName} titleNote={fd.fundingProduct?.name}
          badges={<>
            <Badge tone={statusTone[fd.status] || 'neutral'} dot>{fd.status}</Badge>
            {fd.isMatured && <Badge tone="amber" dot>Matured</Badge>}
            {fd.status === 'Active' && fd.autoRenew && <Badge tone="blue" dot>Auto-renew{fd.autoRenewMode === 'principal_and_interest' ? ' (principal + interest)' : ''}</Badge>}
            {fd.isPledged && <Badge tone="red" dot>Pledged — {Number(fd.lienAmount).toLocaleString()} lien</Badge>}
          </>}
          amountLabel="Principal" amount={money(fd.principalAmount)}
          stats={[
            { label: 'Annual Rate', value: `${Number(fd.interestRatePercent).toFixed(2)}%` },
            { label: 'Term', value: `${fd.termMonths} months` },
            { label: 'Interest', value: fd.interestPaymentMethod === 'monthly' ? 'Monthly' : 'At maturity' },
            { label: 'Maturity Amount', value: money(fd.maturityAmount) },
          ]}
          steps={[
            { label: 'Start', date: fd.startDate },
            { label: 'Maturity', date: fd.maturityDate, done: !!fd.isMatured || fd.status !== 'Active' },
          ]}
          groups={[
            { title: 'Depositor', rows: [
              ['Customer', fd.customer?.fullName],
              ['NIC', fd.customer?.nicNo || '—'],
              ['Product', fd.fundingProduct?.name],
              ['Interest Method', fd.interestPaymentMethod === 'monthly' ? 'Monthly Interest' : 'Interest at Maturity'],
              ...(fd.renewedFrom ? [['Renewed From', fd.renewedFrom.fdNumber] as [string, ReactNode]] : []),
              ...(fd.renewedTo ? [['Renewed To', fd.renewedTo.fdNumber] as [string, ReactNode]] : []),
            ] },
            { title: 'Terms', rows: [
              ['Principal', money(fd.principalAmount)],
              ['Annual Rate', `${Number(fd.interestRatePercent).toFixed(2)}%`],
              ['Term', `${fd.termMonths} months`],
              ['Maturity Amount', money(fd.maturityAmount)],
            ] },
            { title: 'Dates', rows: [
              ['Start Date', dateOnly(fd.startDate)],
              ['Maturity Date', dateOnly(fd.maturityDate)],
            ] },
          ]}
        />
      </div>

      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>Payout Account, Joint Holders &amp; Nominees</div>
        <div className="col gap-2" style={{ fontSize: 12.5 }}>
          <div className="row gap-2" style={{ alignItems: 'center' }}>
            <span className="t-2">Payout account:</span>
            {fd.bankDetail
              ? <b>{fd.bankDetail.bank?.name || 'Bank'} — {fd.bankDetail.accountNumberMasked} ({fd.bankDetail.accountHolder})</b>
              : <Badge tone="amber" dot>Not set</Badge>}
            {fd.status === 'Active' && <Can user={me} module="Fixed Deposits" action="Edit"><Btn size="sm" variant="ghost" onClick={() => { setAccountId(undefined); setAccountOpen(true) }}>{fd.bankDetail ? 'Change' : 'Set account'}</Btn></Can>}
          </div>
          <div><span className="t-2">Joint holders: </span>{(fd.jointHolders || []).length
            ? (fd.jointHolders || []).map((j) => `${j.customer?.fullName}${j.relationship ? ` (${j.relationship})` : ''} — ${j.operatingInstruction === 'both_to_sign' ? 'both to sign' : 'either or survivor'}`).join('; ')
            : <b>None</b>}</div>
          <div><span className="t-2">Nominees: </span>{(fd.nominees || []).length
            ? (fd.nominees || []).map((n) => `${n.fullName}${n.relationship ? ` (${n.relationship})` : ''}${(fd.nominees || []).length > 1 ? ` ${Number(n.sharePercent)}%` : ''}`).join('; ')
            : <b>None</b>}</div>
        </div>
      </Card>

      <Card pad={0} style={{ marginBottom: 16 }}>
        <div className="row between" style={{ padding: '14px 16px 0' }}>
          <div className="eyebrow">Commission</div>
          {fd.status === 'Active' && <Can user={me} module="Fixed Deposits" action="Edit"><Btn size="sm" variant="ghost" icon="plus" onClick={() => { setCommissionForm({ ...noCommission, on: true }); setCommissionErr(''); setCommissionOpen(true) }}>Add commission</Btn></Can>}
        </div>
        <Table cols={[{ label: 'Paid To' }, { label: 'Basis' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: 'Paid On' }, { label: '', align: 'right', w: 150 }]}
          rows={fd.commissions || []} empty="No commission on this FD"
          render={(c: FdCommission) => <>
            <Td>{c.recipientName}<div className="t-2" style={{ fontSize: 11 }}>{c.recipientType === 'staff' ? 'Executive' : 'Outsider'}</div></Td>
            <Td>{c.commissionType === 'percentage' ? `${Number(c.commissionValue)}% of principal` : 'Fixed'}</Td>
            <Td align="right" mono>{money(c.commissionAmount)}</Td>
            <Td align="center"><Badge tone={c.commissionStatus === 'Paid' ? 'green' : 'amber'} dot>{c.commissionStatus}</Badge></Td>
            <Td>{dateOnly(c.paidAt)}</Td>
            <Td align="right">
              {c.commissionStatus === 'Pending' && (
                <Can user={me} module="Fixed Deposits" action="Edit">
                  <Btn size="sm" variant="primary" icon="check" onClick={() => setCommissionPay(c)}>Pay</Btn>
                  <button className="mms-act" title="Delete" onClick={() => removeCommission(c)}><Icon n="trash" s={14} /></button>
                </Can>
              )}
              {c.commissionStatus === 'Paid' && (
                <Can user={me} module="Fixed Deposits" action="Delete">
                  <button className="mms-act" title="Void payment" onClick={() => voidCommission(c)}><Icon n="x" s={14} /></button>
                </Can>
              )}
            </Td>
          </>} />
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
                {p.status === 'Paid' && <>
                  <button className="mms-act" title="Print voucher" onClick={() => printInterestVoucher(p)}><Icon n="print" s={14} /></button>
                  <Can user={me} module="Fixed Deposits" action="Delete">
                    <button className="mms-act" title="Void" onClick={() => voidInterestPayment(p)}><Icon n="trash" s={14} /></button>
                  </Can>
                </>}
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
      <AutoRenewModal fd={fd} open={autoRenewOpen} onClose={() => setAutoRenewOpen(false)} onSaved={() => { setAutoRenewOpen(false); onChanged() }} />
      <WithdrawModal fd={fd} open={withdrawOpen} onClose={() => setWithdrawOpen(false)} onWithdrawn={(w, newFd) => { setWithdrawOpen(false); onPrint({ kind: 'withdrawal', withdrawal: w, fd: newFd }); onChanged() }} />
      <PayFdCommissionModal commission={commissionPay} open={!!commissionPay} onClose={() => setCommissionPay(null)} onPaid={() => { setCommissionPay(null); onChanged() }} />
      <Modal open={commissionOpen} onClose={() => setCommissionOpen(false)} width={520} title="Add Commission" sub={`${fd.fdNumber} — principal ${money(fd.principalAmount)}`}
        footer={<><Btn variant="plain" onClick={() => setCommissionOpen(false)}>Cancel</Btn><Btn variant="primary" icon="check" onClick={addCommission}>Save</Btn></>}>
        <ErrorBanner error={commissionErr} style={{ marginBottom: 14 }} />
        <CommissionFields value={commissionForm} onChange={setCommissionForm} principal={Number(fd.principalAmount)} />
      </Modal>
      <Modal open={accountOpen} onClose={() => setAccountOpen(false)} width={520} title="Payout Account" sub="Interest and maturity proceeds are paid to this account"
        footer={<><Btn variant="plain" onClick={() => setAccountOpen(false)}>Cancel</Btn><Btn variant="primary" icon="check" onClick={savePayoutAccount}>Save</Btn></>}>
        <CustomerBankDetailPicker customerId={fd.customerId} value={accountId} onChange={setAccountId} />
      </Modal>
      <PayInterestModal payment={payTarget} open={!!payTarget} onClose={() => setPayTarget(null)} onPaid={(p) => { setPayTarget(null); onPrint({ kind: 'interest', payment: p }); onChanged() }} />
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
