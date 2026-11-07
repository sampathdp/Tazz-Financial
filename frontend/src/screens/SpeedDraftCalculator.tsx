/* Speed Drafts — Calculator: instant interest-only quote. No math here; every
   figure comes from POST /finance-calculator/speed-draft, which runs the same
   SpeedDraftScheduler a real draft uses. Nothing is saved unless the user
   ever. */
import { useEffect, useRef, useState } from 'react'
import { Card, PageHead, Btn, Field, Input, Select, SearchSelect, Modal, Table, Td, ErrorBanner } from '../components/ui'
import {
  speedDraftCalculatorApi, speedDraftTypesApi, company,
  type SpeedDraftCalcResult, type SpeedDraftType, type AuthUser,
} from '../api'
import { getErrorText } from '../lib/apiError'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)
const PERIOD_LABEL: Record<string, string> = { monthly: 'per month', annual: 'per annum', daily: 'per day' }

function DocRow({ label, value }: { label: string; value: React.ReactNode }) {
  return <div className="row between"><span className="t-2">{label}</span><b>{value}</b></div>
}

function QuoteView({ result, typeName, customerName, brand, preparedBy, onClose }: {
  result: SpeedDraftCalcResult; typeName: string; customerName: string; brand: { name: string; tagline: string }; preparedBy: string; onClose: () => void
}) {
  return (
    <div>
      <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
        <Btn variant="primary" icon="print" onClick={() => window.print()}>Print / Export PDF</Btn>
        <Btn variant="solid" icon="x" onClick={onClose}>Close</Btn>
      </div>
      <Card style={{ maxWidth: 560, margin: '0 auto', fontFamily: 'Outfit' }}>
        <div className="col gap-1" style={{ textAlign: 'center', marginBottom: 18, paddingBottom: 18, borderBottom: '1px dashed var(--line)' }}>
          <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 20 }}>{brand.name || 'Company'}</div>
          {brand.tagline && <div className="t-2" style={{ fontSize: 12 }}>{brand.tagline}</div>}
          <div style={{ fontWeight: 700, fontSize: 14, letterSpacing: '.08em', marginTop: 8 }}>SPEED DRAFT QUOTATION</div>
        </div>
        <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
          {customerName && <DocRow label="Customer" value={customerName} />}
          {typeName && <DocRow label="Speed Draft Type" value={typeName} />}
          <DocRow label="Date" value={todayIso()} />
          <DocRow label="Draft Amount" value={money(result.principalAmount)} />
          <DocRow label="Interest Rate" value={`${Number(result.enteredRate).toFixed(2)}% ${PERIOD_LABEL[result.ratePeriod]}`} />
          <DocRow label="Period" value={`${result.termMonths} Months`} />
          <DocRow label="Total Interest" value={money(result.totalInterest)} />
          <DocRow label="Total Payable at Maturity" value={money(result.maturityPayable)} />
        </div>
        <div className="row between" style={{ fontSize: 15, fontWeight: 700 }}>
          <span>Monthly Interest</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(result.firstInstallmentInterest)}</span>
        </div>
        <div className="t-2" style={{ fontSize: 11.5, marginTop: 8 }}>Interest-only; capital is settled at maturity or by part-payment, which lowers later interest.</div>
        <div className="row between" style={{ fontSize: 12, marginTop: 30 }}>
          <div>Prepared By: {preparedBy || '______________'}</div>
          <div>Signature: ______________</div>
        </div>
      </Card>
    </div>
  )
}

export function SpeedDraftCalculatorScreen({ user: me }: { go?: unknown; user?: AuthUser | null }) {
  const [types, setTypes] = useState<SpeedDraftType[]>([])
  const [typeId, setTypeId] = useState<number | undefined>()
  const [amount, setAmount] = useState('')
  const [termUnit, setTermUnit] = useState<'months' | 'years'>('months')
  const [termValue, setTermValue] = useState('')
  const [rate, setRate] = useState('')
  const [ratePeriod, setRatePeriod] = useState<'monthly' | 'annual' | 'daily'>('monthly')
  const [startDate, setStartDate] = useState(todayIso())
  const [customerName, setCustomerName] = useState('')

  const [result, setResult] = useState<SpeedDraftCalcResult | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [quoteOpen, setQuoteOpen] = useState(false)
  const [brand, setBrand] = useState({ name: '', tagline: '' })
  const debounceRef = useRef<ReturnType<typeof setTimeout>>()

  useEffect(() => { speedDraftTypesApi.list().then(setTypes).catch(() => setTypes([])) }, [])
  useEffect(() => { company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {}) }, [])

  const selectedType = types.find((t) => t.id === typeId)
  const termMonths = termUnit === 'years' ? Math.round(Number(termValue || 0) * 12) : Math.round(Number(termValue || 0))

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    const p = Number(amount), r = Number(rate)
    if (!p || p <= 0 || !termMonths || termMonths <= 0 || !r || r <= 0) { setResult(null); setErr(''); return }
    debounceRef.current = setTimeout(async () => {
      setBusy(true); setErr('')
      try {
        setResult(await speedDraftCalculatorApi.calculate({
          principalAmount: p, termMonths, interestRatePercent: r, ratePeriod, startDate: startDate || undefined,
        }))
      } catch (ex: any) {
        setErr(getErrorText(ex, 'Could not calculate the quote.')); setResult(null)
      } finally { setBusy(false) }
    }, 300)
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current) }
  }, [amount, termMonths, rate, ratePeriod, startDate])

  if (quoteOpen && result) {
    return <QuoteView result={result} typeName={selectedType?.name || ''} customerName={customerName} brand={brand} preparedBy={me?.name || ''} onClose={() => setQuoteOpen(false)} />
  }

  const tail = result && result.schedule.length > 4
    ? [...result.schedule.slice(0, 3), null, result.schedule[result.schedule.length - 1]]
    : result?.schedule || []

  return (
    <div>
      <PageHead crumbs="Speed Drafts" title="Speed Draft Calculator" icon="calc" sub="Instant interest-only quotes — same engine as Speed Draft schedules" />
      <div className="row gap-3" style={{ alignItems: 'flex-start', flexWrap: 'wrap' }}>
        <Card style={{ flex: '1 1 380px', minWidth: 340 }}>
          <div className="col gap-3">
            <div className="eyebrow">Speed Draft</div>
            <Field label="Speed Draft Type (optional)">
              <SearchSelect
                value={selectedType ? selectedType.name : ''}
                onChange={(_n, opt: SpeedDraftType | undefined) => {
                  setTypeId(opt?.id)
                  if (opt) {
                    setRate(opt.interestRatePercent != null ? String(opt.interestRatePercent) : '')
                    if (opt.ratePeriod) setRatePeriod(opt.ratePeriod)
                  }
                }}
                options={types}
                getLabel={(t: SpeedDraftType) => t.name}
                getSub={(t: SpeedDraftType) => t.code}
                placeholder="Search type…"
              />
              {selectedType && (
                <div className="t-2" style={{ fontSize: 11.5, marginTop: 4 }}>
                  {selectedType.category} · amount {Number(selectedType.minAmount || 0).toLocaleString()}–{Number(selectedType.maxAmount || 0).toLocaleString()} · term {selectedType.minPeriodMonths ?? '—'}–{selectedType.maxPeriodMonths ?? '—'}mo
                </div>
              )}
            </Field>
            <Field label="Draft Amount"><Input type="number" min={0} value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="0.00" /></Field>
            <div className="row gap-2">
              <Field label="Period" style={{ flex: 1 }}><Input type="number" min={0} value={termValue} onChange={(e) => setTermValue(e.target.value)} placeholder="0" /></Field>
              <Field label="Unit" style={{ width: 130 }}>
                <Select value={termUnit} onChange={(e) => setTermUnit(e.target.value as 'months' | 'years')}>
                  <option value="months">Months</option>
                  <option value="years">Years</option>
                </Select>
              </Field>
            </div>
            <div className="row gap-2">
              <Field label="Interest Rate (%)" style={{ flex: 1 }}><Input type="number" min={0} step="0.01" value={rate} onChange={(e) => setRate(e.target.value)} placeholder="0.00" /></Field>
              <Field label="Rate Period" style={{ width: 150 }}>
                <Select value={ratePeriod} onChange={(e) => setRatePeriod(e.target.value as 'monthly' | 'annual' | 'daily')}>
                  <option value="monthly">Per month</option>
                  <option value="annual">Per annum</option>
                  <option value="daily">Per day</option>
                </Select>
              </Field>
            </div>
            <Field label="Start Date"><Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} /></Field>
            <Field label="Customer Name (optional, for quotation)"><Input value={customerName} onChange={(e) => setCustomerName(e.target.value)} placeholder="Customer name" /></Field>
          </div>
        </Card>

        <div className="col gap-3" style={{ flex: '1 1 420px', minWidth: 340 }}>
          <Card>
            <ErrorBanner error={err} style={{ marginBottom: 14 }} />
            {!result && !err && (
              <div className="t-2" style={{ fontSize: 13, padding: '10px 0' }}>
                {busy ? 'Calculating…' : 'Enter amount, period and rate to see the quote.'}
              </div>
            )}
            {result && (
              <div className="col gap-2" style={{ fontSize: 13, opacity: busy ? 0.6 : 1 }}>
                <DocRow label="Draft Amount" value={money(result.principalAmount)} />
                <DocRow label="Entered Rate" value={`${Number(result.enteredRate).toFixed(2)}% ${PERIOD_LABEL[result.ratePeriod]}`} />
                <DocRow label="Period" value={`${result.termMonths} Months`} />
                <div className="row between" style={{ fontSize: 16, fontWeight: 700, padding: '8px 0', borderTop: '1px solid var(--line)', borderBottom: '1px solid var(--line)' }}>
                  <span>Monthly Interest</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(result.firstInstallmentInterest)}</span>
                </div>
                <DocRow label="Total Interest" value={money(result.totalInterest)} />
                <DocRow label="Total Payable at Maturity" value={money(result.maturityPayable)} />
                <div className="t-2" style={{ fontSize: 11.5 }}>Interest-only: capital is due at maturity (or via part-payment, which lowers later interest).</div>
                <div className="row gap-2" style={{ marginTop: 10, flexWrap: 'wrap' }}>
                  <Btn variant="primary" icon="doc" onClick={() => setQuoteOpen(true)}>Generate Quotation</Btn>
                </div>
              </div>
            )}
          </Card>

          {result && (
            <Card pad={0}>
              <div className="row between" style={{ padding: '14px 16px' }}>
                <div className="eyebrow">Schedule Preview</div>
                <Btn size="sm" variant="ghost" icon="list" onClick={() => setScheduleOpen(true)}>View Full Schedule</Btn>
              </div>
              <Table
                cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Interest', align: 'right' }, { label: 'Capital', align: 'right' }, { label: 'Total', align: 'right' }]}
                rows={tail}
                render={(row, i) => row === null
                  ? <td key={i} colSpan={5} style={{ padding: '11px 14px', textAlign: 'center', color: 'var(--tx-3)', borderBottom: '1px solid var(--line-soft)' }}>⋯</td>
                  : <>
                    <Td>{row.installmentNo}</Td>
                    <Td>{dateOnly(row.dueDate)}</Td>
                    <Td align="right" mono>{money(row.interestDue)}</Td>
                    <Td align="right" mono>{money(row.principalDue)}</Td>
                    <Td align="right" mono>{money(row.totalDue)}</Td>
                  </>} />
            </Card>
          )}
        </div>
      </div>

      <Modal open={scheduleOpen} onClose={() => setScheduleOpen(false)} width={680} title="Full Schedule">
        <Table
          cols={[{ label: '#' }, { label: 'Due Date' }, { label: 'Interest', align: 'right' }, { label: 'Capital', align: 'right' }, { label: 'Total', align: 'right' }]}
          rows={result?.schedule || []}
          render={(row) => <>
            <Td>{row.installmentNo}</Td>
            <Td>{dateOnly(row.dueDate)}</Td>
            <Td align="right" mono>{money(row.interestDue)}</Td>
            <Td align="right" mono>{money(row.principalDue)}</Td>
            <Td align="right" mono>{money(row.totalDue)}</Td>
          </>} />
      </Modal>
    </div>
  )
}

export default SpeedDraftCalculatorScreen
