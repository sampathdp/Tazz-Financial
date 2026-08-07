/* Finance — Leasing / Loan Calculator: an instant quote tool with no calculation
   logic of its own. Every figure comes from POST /finance-calculator/calculate,
   which runs App\Support\FinanceCalculationService — the same LoanAmortizer/
   AerCalculator classes LoanController::disburse() uses. No DB record is created
   unless the user clicks "Create Loan", and even then this screen only hands off
   plain field values to the normal Loan Applications form. */
import { useEffect, useRef, useState } from 'react'
import { Card, PageHead, Btn, Field, Input, Select, SearchSelect, Modal, Table, Td, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  financeCalculatorApi, loanTypesApi, company,
  type FinanceCalcResult, type LoanFrequency, type LoanType, type AuthUser,
} from '../api'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const pct = (v: number | string | null | undefined) => `${Number(v ?? 0).toFixed(2)}%`
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)
const rateTypeLabel = (m?: string | null) => (m === 'flat' ? 'Flat Rate' : m === 'reducing_balance' ? 'Reducing Balance (AER)' : '—')

/** Same parent-chain path builder as LoanApplications.tsx / LoanTypes.tsx —
 *  keyed by String(id) since some hosts return integer columns as JSON strings. */
function loanTypePath(rows: LoanType[], id: number | null | undefined): string {
  if (!id) return ''
  const byId = new Map(rows.map((r) => [String(r.id), r]))
  const chain: string[] = []
  let cur = byId.get(String(id))
  while (cur) { chain.unshift(cur.name); cur = cur.parentId ? byId.get(String(cur.parentId)) : undefined }
  return chain.join(' › ')
}

const FREQ_OPTIONS: { value: LoanFrequency; label: string }[] = [
  { value: 'monthly', label: 'Monthly' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'daily', label: 'Daily' },
]
const CHARGE_PRESETS = [
  'Documentation Fee', 'Insurance Premium', 'Processing Fee', 'Stamp Duty',
  'Valuation Fee', 'GPS Installation', 'Registration Charges', 'Other Charges',
]

interface ChargeRow { id: number; label: string; amount: string; financed: boolean }
let chargeSeq = 1
const blankCharge = (): ChargeRow => ({ id: chargeSeq++, label: CHARGE_PRESETS[0], amount: '', financed: true })

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

function QuoteView({ result, customerName, brand, preparedBy, onClose }: {
  result: FinanceCalcResult; customerName: string; brand: { name: string; tagline: string }; preparedBy: string; onClose: () => void
}) {
  return (
    <div>
      <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
        <Btn variant="primary" icon="print" onClick={() => window.print()}>Print / Export PDF</Btn>
        <Btn variant="solid" icon="x" onClick={onClose}>Close</Btn>
      </div>
      <Card style={{ maxWidth: 560, margin: '0 auto', fontFamily: 'Outfit' }}>
        <DocHeader brand={brand} title="LEASING / LOAN QUOTATION" />
        <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
          {customerName && <DocRow label="Customer" value={customerName} />}
          <DocRow label="Date" value={todayIso()} />
          <DocRow label="Loan Amount" value={money(result.loanAmount)} />
          {result.downPayment > 0 && <DocRow label="Down Payment" value={money(result.downPayment)} />}
          {result.financedChargesTotal > 0 && <DocRow label="Financed Charges" value={money(result.financedChargesTotal)} />}
          <DocRow label="Finance Amount" value={money(result.financeAmount)} />
        </div>
        <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
          <DocRow label="Interest Type" value={rateTypeLabel(result.interestMethod)} />
          <DocRow label="Entered Rate" value={pct(result.enteredRate)} />
          {result.effectiveAnnualRate != null && <DocRow label="Equivalent AER" value={pct(result.effectiveAnnualRate)} />}
          <DocRow label="Loan Period" value={`${result.termMonths} Months`} />
          <DocRow label="Payment Frequency" value={result.installmentFrequency} />
          <DocRow label="Total Interest" value={money(result.totalInterest)} />
          <DocRow label="Total Payable" value={money(result.totalPayable)} />
          {result.separateChargesTotal > 0 && <DocRow label="Customer-Paid Charges" value={money(result.separateChargesTotal)} />}
        </div>
        <div className="row between" style={{ fontSize: 15, fontWeight: 700 }}>
          <span>Monthly Rental</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(result.monthlyRental)}</span>
        </div>
        <div className="row between" style={{ fontSize: 12, marginTop: 30 }}>
          <div>Prepared By: {preparedBy || '______________'}</div>
          <div>Signature: ______________</div>
        </div>
      </Card>
    </div>
  )
}

export function LoanCalculatorScreen({ go, user: me }: { go: Go; user?: AuthUser | null }) {
  const [loanAmount, setLoanAmount] = useState('')
  const [downPayment, setDownPayment] = useState('')
  const [termUnit, setTermUnit] = useState<'months' | 'years'>('months')
  const [termValue, setTermValue] = useState('')
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [loanTypeId, setLoanTypeId] = useState<number | undefined>()
  const [interestRatePercent, setInterestRatePercent] = useState('')
  const [installmentFrequency, setInstallmentFrequency] = useState<LoanFrequency>('monthly')
  const [charges, setCharges] = useState<ChargeRow[]>([])
  const [customerName, setCustomerName] = useState('')

  useEffect(() => { loanTypesApi.list().then(setLoanTypes).catch(() => setLoanTypes([])) }, [])
  const products = loanTypes.filter((t) => t.isProduct)
  // Interest method and rate period are product attributes, never user-chosen —
  // exactly how LoanController::store() sets them (loanType.interest_method /
  // .rate_period), so the calculator can't quote a method/period combination
  // the real loan-creation flow would never produce.
  const selectedType = loanTypes.find((t) => t.id === loanTypeId)
  const interestMethod = selectedType?.interestMethod
  const ratePeriod = selectedType?.ratePeriod || 'monthly'

  const [result, setResult] = useState<FinanceCalcResult | null>(null)
  const [calcBusy, setCalcBusy] = useState(false)
  const [err, setErr] = useState('')
  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [quoteOpen, setQuoteOpen] = useState(false)
  const [brand, setBrand] = useState({ name: '', tagline: '' })
  const debounceRef = useRef<ReturnType<typeof setTimeout>>()

  useEffect(() => { company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {}) }, [])

  const termMonths = termUnit === 'years' ? Math.round(Number(termValue || 0) * 12) : Math.round(Number(termValue || 0))

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    const la = Number(loanAmount)
    const dp = Number(downPayment || 0)
    const rate = Number(interestRatePercent)
    if (!interestMethod) { setResult(null); setErr(''); return }
    if (!la || la <= 0 || !termMonths || termMonths <= 0 || !rate || rate <= 0) { setResult(null); setErr(''); return }
    if (dp > la) { setErr('Down payment cannot exceed the loan amount.'); setResult(null); return }
    if (charges.some((c) => Number(c.amount) < 0)) { setErr('Charges cannot be negative.'); setResult(null); return }
    debounceRef.current = setTimeout(async () => {
      setCalcBusy(true); setErr('')
      try {
        const r = await financeCalculatorApi.calculate({
          loanTypeId, loanAmount: la, downPayment: dp, termMonths, interestMethod, interestRatePercent: rate,
          ratePeriod, installmentFrequency,
          charges: charges.filter((c) => Number(c.amount) > 0).map((c) => ({ label: c.label || undefined, amount: Number(c.amount), financed: c.financed })),
        })
        setResult(r)
      } catch (ex: any) {
        setErr(getErrorText(ex, 'Could not calculate the quote.')); setResult(null)
      } finally { setCalcBusy(false) }
    }, 300)
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loanTypeId, loanAmount, downPayment, termMonths, interestMethod, ratePeriod, interestRatePercent, installmentFrequency, charges])

  const addCharge = () => setCharges((cs) => [...cs, blankCharge()])
  const updateCharge = (id: number, patch: Partial<ChargeRow>) => setCharges((cs) => cs.map((c) => (c.id === id ? { ...c, ...patch } : c)))
  const removeCharge = (id: number) => setCharges((cs) => cs.filter((c) => c.id !== id))

  const createLoan = () => {
    if (!result) return
    sessionStorage.setItem('mms-loan-calc-prefill', JSON.stringify({
      loanTypeId, principalAmount: result.financeAmount, downPayment: 0, termMonths: result.termMonths,
      installmentFrequency: result.installmentFrequency, interestRatePercent: result.enteredRate,
    }))
    go('loan/apps')
  }

  if (quoteOpen && result) {
    return <QuoteView result={result} customerName={customerName} brand={brand} preparedBy={me?.name || ''} onClose={() => setQuoteOpen(false)} />
  }

  const scheduleTail = result && result.schedule.length > 4
    ? [...result.schedule.slice(0, 3), null, result.schedule[result.schedule.length - 1]]
    : result?.schedule || []

  return (
    <div>
      <PageHead crumbs="Finance" title="Leasing / Loan Calculator" icon="calc" sub="Instant quotes — same engine as loan disbursement" />

      <div className="row gap-3" style={{ alignItems: 'flex-start', flexWrap: 'wrap' }}>
        <Card style={{ flex: '1 1 380px', minWidth: 340 }}>
          <div className="col gap-3">
            <div className="eyebrow">Loan Information</div>
            <Field label="Loan Amount / Asset Value"><Input type="number" min={0} value={loanAmount} onChange={(e) => setLoanAmount(e.target.value)} placeholder="0.00" /></Field>
            <Field label="Down Payment (optional)"><Input type="number" min={0} value={downPayment} onChange={(e) => setDownPayment(e.target.value)} placeholder="0.00" /></Field>
            <div className="row gap-2">
              <Field label="Loan Term" style={{ flex: 1 }}><Input type="number" min={0} value={termValue} onChange={(e) => setTermValue(e.target.value)} placeholder="0" /></Field>
              <Field label="Unit" style={{ width: 130 }}>
                <Select value={termUnit} onChange={(e) => setTermUnit(e.target.value as 'months' | 'years')}>
                  <option value="months">Months</option>
                  <option value="years">Years</option>
                </Select>
              </Field>
            </div>

            <div className="eyebrow">Interest</div>
            <Field label="Loan Type / Product">
              <SearchSelect
                value={selectedType ? loanTypePath(loanTypes, selectedType.id) : ''}
                onChange={(_n, opt: LoanType | undefined) => {
                  setLoanTypeId(opt?.id)
                  if (opt) {
                    setInterestRatePercent(opt.interestRatePercent != null ? String(opt.interestRatePercent) : '')
                    if (opt.frequency) setInstallmentFrequency(opt.frequency)
                  }
                }}
                options={products}
                getLabel={(t: LoanType) => loanTypePath(loanTypes, t.id)}
                getSub={(t: LoanType) => t.code}
                placeholder="Search loan product…"
              />
              {selectedType ? (
                <div className="t-2" style={{ fontSize: 11.5, marginTop: 4 }}>
                  {rateTypeLabel(selectedType.interestMethod)} · {ratePeriod === 'annual' ? 'per annum' : 'per month'} · amount {Number(selectedType.minAmount || 0).toLocaleString()}–{Number(selectedType.maxAmount || 0).toLocaleString()} · term {selectedType.minTermMonths}–{selectedType.maxTermMonths}mo
                </div>
              ) : (
                <div className="t-2" style={{ fontSize: 11.5, marginTop: 4 }}>Rate type and rate period come from the selected product, same as Loan Applications.</div>
              )}
            </Field>
            <Field label="Interest Rate (%)"><Input type="number" min={0} step="0.01" value={interestRatePercent} onChange={(e) => setInterestRatePercent(e.target.value)} placeholder="0.00" disabled={!selectedType} /></Field>
            <Field label="Payment Frequency">
              <Select value={installmentFrequency} onChange={(e) => setInstallmentFrequency(e.target.value as LoanFrequency)}>
                {FREQ_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </Select>
            </Field>

            <div className="row between" style={{ alignItems: 'center' }}>
              <div className="eyebrow">Fees & Charges</div>
              <Btn size="sm" icon="plus" onClick={addCharge}>Add Charge</Btn>
            </div>
            {charges.length === 0 && <div className="t-2" style={{ fontSize: 12.5 }}>No additional fees added.</div>}
            {charges.map((c) => (
              <div key={c.id} className="row gap-2" style={{ alignItems: 'center' }}>
                <Select style={{ flex: '1 1 150px' }} value={CHARGE_PRESETS.includes(c.label) ? c.label : 'Other Charges'}
                  onChange={(e) => updateCharge(c.id, { label: e.target.value })}>
                  {CHARGE_PRESETS.map((p) => <option key={p} value={p}>{p}</option>)}
                </Select>
                {!CHARGE_PRESETS.includes(c.label) && (
                  <Input style={{ flex: '1 1 120px' }} value={c.label} onChange={(e) => updateCharge(c.id, { label: e.target.value })} placeholder="Charge name" />
                )}
                <Input style={{ width: 110 }} type="number" min={0} value={c.amount} onChange={(e) => updateCharge(c.id, { amount: e.target.value })} placeholder="Amount" />
                <Select style={{ width: 150 }} value={c.financed ? 'financed' : 'separate'} onChange={(e) => updateCharge(c.id, { financed: e.target.value === 'financed' })}>
                  <option value="financed">Finance this fee</option>
                  <option value="separate">Customer pays separately</option>
                </Select>
                <Btn variant="plain" size="sm" icon="trash" onClick={() => removeCharge(c.id)} />
              </div>
            ))}

            <Field label="Customer Name (optional, for quotation)"><Input value={customerName} onChange={(e) => setCustomerName(e.target.value)} placeholder="Customer name" /></Field>
          </div>
        </Card>

        <div className="col gap-3" style={{ flex: '1 1 420px', minWidth: 340 }}>
          <Card>
            <ErrorBanner error={err} style={{ marginBottom: 14 }} />
            {!result && !err && (
              <div className="t-2" style={{ fontSize: 13, padding: '10px 0' }}>
                {calcBusy ? 'Calculating…' : !selectedType ? 'Select a Loan Type / Product to begin.' : 'Enter loan amount, term and rate to see the quote.'}
              </div>
            )}
            {result && (
              <div className="col gap-2" style={{ fontSize: 13, opacity: calcBusy ? 0.6 : 1 }}>
                <DocRow label="Finance Amount" value={money(result.financeAmount)} />
                <DocRow label="Interest Type" value={rateTypeLabel(result.interestMethod)} />
                <DocRow label="Entered Rate" value={pct(result.enteredRate)} />
                {result.effectiveMonthlyRate != null && <DocRow label="Equivalent Monthly Effective Rate" value={pct(result.effectiveMonthlyRate)} />}
                {result.effectiveAnnualRate != null && <DocRow label="Equivalent Annual Effective Rate (AER)" value={pct(result.effectiveAnnualRate)} />}
                <DocRow label="Loan Period" value={`${result.termMonths} Months`} />
                <div className="row between" style={{ fontSize: 16, fontWeight: 700, padding: '8px 0', borderTop: '1px solid var(--line)', borderBottom: '1px solid var(--line)' }}>
                  <span>Monthly Rental</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(result.monthlyRental)}</span>
                </div>
                <DocRow label="Total Interest" value={money(result.totalInterest)} />
                <DocRow label="Total Amount Payable" value={money(result.totalPayable)} />
                <DocRow label="Customer Upfront Payment" value={money(result.customerUpfrontPayment)} />
                <DocRow label="Financed Charges" value={money(result.financedChargesTotal)} />
                <DocRow label="Separate Charges" value={money(result.separateChargesTotal)} />

                <div className="row gap-2" style={{ marginTop: 10, flexWrap: 'wrap' }}>
                  <Btn variant="primary" icon="doc" onClick={() => setQuoteOpen(true)}>Generate Quotation</Btn>
                  <Btn variant="solid" icon="check" onClick={createLoan}>Create Loan</Btn>
                </div>
              </div>
            )}
          </Card>

          {result && (
            <Card pad={0}>
              <div className="row between" style={{ padding: '14px 16px' }}>
                <div className="eyebrow">Repayment Preview</div>
                <Btn size="sm" variant="ghost" icon="list" onClick={() => setScheduleOpen(true)}>View Full Schedule</Btn>
              </div>
              <Table
                cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Principal', align: 'right' }, { label: 'Interest', align: 'right' }, { label: 'Balance', align: 'right' }]}
                rows={scheduleTail}
                render={(row, i) => row === null
                  ? <td key={i} colSpan={5} style={{ padding: '11px 14px', textAlign: 'center', color: 'var(--tx-3)', borderBottom: '1px solid var(--line-soft)' }}>⋯</td>
                  : <>
                    <Td>{row.installmentNo}</Td>
                    <Td>{dateOnly(row.dueDate)}</Td>
                    <Td align="right" mono>{money(row.principalDue)}</Td>
                    <Td align="right" mono>{money(row.interestDue)}</Td>
                    <Td align="right" mono>{money(row.balance)}</Td>
                  </>} />
            </Card>
          )}
        </div>
      </div>

      <Modal open={scheduleOpen} onClose={() => setScheduleOpen(false)} width={720} title="Full Repayment Schedule">
        <Table
          cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Principal', align: 'right' }, { label: 'Interest', align: 'right' }, { label: 'Total', align: 'right' }, { label: 'Balance', align: 'right' }]}
          rows={result?.schedule || []}
          render={(row) => <>
            <Td>{row.installmentNo}</Td>
            <Td>{dateOnly(row.dueDate)}</Td>
            <Td align="right" mono>{money(row.principalDue)}</Td>
            <Td align="right" mono>{money(row.interestDue)}</Td>
            <Td align="right" mono>{money(row.totalDue)}</Td>
            <Td align="right" mono>{money(row.balance)}</Td>
          </>} />
      </Modal>
    </div>
  )
}

export default LoanCalculatorScreen
