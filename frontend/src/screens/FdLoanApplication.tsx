/* Loan Management — Loan against Fixed Deposit: pick a customer's FD, the limit and rate come from the FD product's policy. */
import { useEffect, useMemo, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, ErrorBanner, LoadingBlock } from '../components/ui'
import { customersApi, loanTypesApi, fdLoansApi, type Customer, type LoanType, type EligibleFd } from '../api'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0) || 0
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const FREQUENCIES = ['monthly', 'weekly', 'daily'] as const

export function FdLoanApplicationScreen({ go }: { go: Go }) {
  const notify = useNotify()
  const [customers, setCustomers] = useState<Customer[]>([])
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [customerName, setCustomerName] = useState('')
  const [customerId, setCustomerId] = useState<number | null>(null)
  const [fds, setFds] = useState<EligibleFd[]>([])
  const [loadingFds, setLoadingFds] = useState(false)
  const [fdId, setFdId] = useState<number | null>(null)
  const [loanTypeId, setLoanTypeId] = useState('')
  const [amount, setAmount] = useState('')
  const [term, setTerm] = useState('')
  const [frequency, setFrequency] = useState<(typeof FREQUENCIES)[number]>('monthly')
  const [purpose, setPurpose] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    customersApi.list().then(setCustomers).catch(() => setCustomers([]))
    loanTypesApi.list().then((t) => setLoanTypes(t.filter((x) => x.isProduct))).catch(() => setLoanTypes([]))
  }, [])

  const pickCustomer = (name: string, c?: Customer) => {
    setCustomerName(name); setCustomerId(c?.id ?? null)
    setFds([]); setFdId(null); setAmount(''); setTerm(''); setErr('')
    if (!c) return
    setLoadingFds(true)
    fdLoansApi.eligibleFds(c.id).then(setFds).catch((ex) => setErr(getErrorText(ex, 'Could not load FDs'))).finally(() => setLoadingFds(false))
  }

  const fd = useMemo(() => fds.find((f) => f.id === fdId) || null, [fds, fdId])
  const quote = fd?.loanQuote
  const loanType = useMemo(() => loanTypes.find((t) => String(t.id) === loanTypeId) || null, [loanTypes, loanTypeId])

  const pickFd = (f: EligibleFd) => {
    if (!f.loanQuote.eligible) return
    setFdId(f.id)
    setAmount(String(f.loanQuote.maxLoanAmount))
    setTerm(f.loanQuote.maxTermMonths ? String(f.loanQuote.maxTermMonths) : '')
    setErr('')
  }

  const problems = (): string => {
    if (!fd || !quote) return 'Select an FD to pledge.'
    if (!loanType) return 'Select a loan type.'
    const a = num(amount), t = parseInt(term, 10)
    if (!(a > 0)) return 'Enter the loan amount.'
    if (a > quote.maxLoanAmount + 0.005) return `Amount exceeds the maximum of ${money(quote.maxLoanAmount)} for this FD.`
    if (a < quote.minLoanAmount) return `Amount is below the minimum of ${money(quote.minLoanAmount)}.`
    if (loanType.minAmount != null && a < num(loanType.minAmount)) return `${loanType.name} minimum is ${money(loanType.minAmount)}.`
    if (loanType.maxAmount != null && a > num(loanType.maxAmount)) return `${loanType.name} maximum is ${money(loanType.maxAmount)}.`
    if (!(t > 0)) return 'Enter the term in months.'
    if (quote.maxTermMonths && t > quote.maxTermMonths) return `Term cannot pass the FD's maturity — at most ${quote.maxTermMonths} month(s).`
    if (loanType.minTermMonths != null && t < loanType.minTermMonths) return `${loanType.name} minimum term is ${loanType.minTermMonths} months.`
    if (loanType.maxTermMonths != null && t > loanType.maxTermMonths) return `${loanType.name} maximum term is ${loanType.maxTermMonths} months.`
    return ''
  }

  const submit = async () => {
    const p = problems()
    if (p) { setErr(p); return }
    setBusy(true); setErr('')
    try {
      const loan = await fdLoansApi.create({
        branchId: fd!.branchId, fixedDepositId: fd!.id, loanTypeId: loanType!.id,
        principalAmount: num(amount), termMonths: parseInt(term, 10), installmentFrequency: frequency,
        purpose: purpose || undefined,
      })
      notify.success('Loan application created', `${loan.code} — FD ${fd!.fdNumber} is now pledged`)
      go(`loan/apps/${loan.id}`)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not create the loan'))
    } finally { setBusy(false) }
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Loan against Fixed Deposit" icon="coins"
        sub="The FD is frozen as security until the loan is closed. Limit and rate come from the FD product's loan policy."
        actions={<Btn variant="solid" icon="chev" onClick={() => go('loan/apps')}>Back to Applications</Btn>} />
      <ErrorBanner error={err} style={{ marginBottom: 14 }} />

      <Card style={{ marginBottom: 16 }}>
        <div className="eyebrow" style={{ marginBottom: 10 }}>1. Customer</div>
        <SearchSelect value={customerName} onChange={pickCustomer} options={customers}
          getLabel={(c: Customer) => c.fullName} getSub={(c: Customer) => [c.code, c.nicNo].filter(Boolean).join(' · ')} placeholder="Search customer by name, code or NIC…" />
      </Card>

      {customerId && (
        <Card style={{ marginBottom: 16 }}>
          <div className="eyebrow" style={{ marginBottom: 10 }}>2. Fixed deposit to pledge</div>
          {loadingFds ? <LoadingBlock /> : !fds.length ? <div className="t-2" style={{ fontSize: 13 }}>This customer has no active fixed deposits.</div> : (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 12 }}>
              {fds.map((f) => {
                const q = f.loanQuote
                const selected = f.id === fdId
                return (
                  <button key={f.id} type="button" onClick={() => pickFd(f)} disabled={!q.eligible}
                    style={{
                      textAlign: 'left', padding: 14, borderRadius: 'var(--r-m)', cursor: q.eligible ? 'pointer' : 'not-allowed',
                      border: `1.5px solid ${selected ? 'var(--accent)' : 'var(--line)'}`, background: 'var(--bg-1)', opacity: q.eligible ? 1 : 0.6,
                    }}>
                    <div className="row between"><b>{f.fdNumber}</b>{q.eligible ? <Badge tone="green" dot>Eligible</Badge> : <Badge tone="red" dot>Not eligible</Badge>}</div>
                    <div className="t-2" style={{ fontSize: 12, marginTop: 4 }}>{f.fundingProduct?.name} · matures {f.maturityDate}</div>
                    <div style={{ marginTop: 8, fontSize: 13 }}>Principal <b>{money(f.principalAmount)}</b> @ {num(f.interestRatePercent).toFixed(2)}%</div>
                    {q.eligible
                      ? <div style={{ marginTop: 4, fontSize: 13 }}>Can borrow up to <b>{money(q.maxLoanAmount)}</b> at <b>{q.interestRatePercent?.toFixed(2)}%</b> p.a.</div>
                      : <div style={{ marginTop: 4, fontSize: 12, color: 'var(--red)' }}>{q.reason}</div>}
                  </button>
                )
              })}
            </div>
          )}
          {!loadingFds && fds.some((f) => !f.loanQuote.eligible && f.loanQuote.reason?.includes('Funding Products')) && (
            <div className="row between wrap gap-2" style={{ marginTop: 14, padding: '10px 12px', borderRadius: 'var(--r-s)', border: '1px solid var(--warn)', background: 'var(--warn-dim)', color: 'var(--warn)', fontSize: 12.5 }}>
              <span>An FD is not eligible because its product has no active loan policy. Open Funding Products and use the shield icon to set the maximum loan %, rate and minimum days to maturity.</span>
              <Btn size="sm" variant="solid" onClick={() => go('fund/products')}>Open Funding Products</Btn>
            </div>
          )}
        </Card>
      )}

      {fd && quote && (
        <Card>
          <div className="eyebrow" style={{ marginBottom: 10 }}>3. Loan terms</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Loan Type" full>
              <Select value={loanTypeId} onChange={(e) => setLoanTypeId(e.target.value)}>
                <option value="">Select…</option>
                {loanTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </Select>
            </Field>
            <Field label={`Loan Amount (max ${money(quote.maxLoanAmount)})`}>
              <Input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
            </Field>
            <Field label={`Term (months${quote.maxTermMonths ? `, max ${quote.maxTermMonths}` : ''})`}>
              <Input type="number" value={term} onChange={(e) => setTerm(e.target.value)} />
            </Field>
            <Field label="Installment Frequency">
              <Select value={frequency} onChange={(e) => setFrequency(e.target.value as typeof frequency)}>
                {FREQUENCIES.map((f) => <option key={f} value={f}>{f[0].toUpperCase() + f.slice(1)}</option>)}
              </Select>
            </Field>
            <Field label="Interest Rate (annual, set by FD policy)">
              <Input value={`${quote.interestRatePercent?.toFixed(2)}%`} disabled />
            </Field>
            <Field label="Purpose (optional)" full><Input value={purpose} onChange={(e) => setPurpose(e.target.value)} /></Field>
          </div>
          <div className="t-2" style={{ fontSize: 12, margin: '14px 0' }}>
            Submitting pledges {fd.fdNumber} immediately ({money(fd.principalAmount)} frozen). It cannot be renewed or withdrawn until the loan is rejected, cancelled or closed.
          </div>
          <Btn variant="primary" icon="check" onClick={submit} disabled={busy}>{busy ? 'Submitting…' : 'Create Loan Application'}</Btn>
        </Card>
      )}
    </div>
  )
}

export default FdLoanApplicationScreen
