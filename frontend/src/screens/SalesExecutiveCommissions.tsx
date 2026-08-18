/* Tazz Investment & Credit — Sales Executive Commissions */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Modal, Table, Td, ErrorBanner } from '../components/ui'
import {
  api, loanTypesApi, salesExecutiveCommissionsApi, cashAccountsApi, bankAccountsApi,
  type AuthUser, type LoanType, type SalesExecutiveCommission, type CommissionStatus, type PaymentMethod,
  type CashAccount, type BankAccount,
} from '../api'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const commissionTone = (s: CommissionStatus) => s === 'Paid' ? 'green' : s === 'Eligible' ? 'blue' : 'amber'
const paymentTone = (s: string) => s === 'Overdue' ? 'red' : s === 'Closed' ? 'neutral' : 'green'

interface PayForm {
  paymentDate: string; paymentAmount: number | string; paymentMethod: PaymentMethod
  cashAccountId: number | ''; bankAccountId: number | ''
  referenceNo: string; notes: string
}
const blankPayForm = (c: SalesExecutiveCommission): PayForm => ({
  paymentDate: new Date().toISOString().slice(0, 10),
  paymentAmount: c.commissionAmount,
  paymentMethod: 'Cash',
  cashAccountId: '', bankAccountId: '',
  referenceNo: '', notes: '',
})

export function SalesExecutiveCommissionsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<SalesExecutiveCommission[]>([])
  const [loading, setLoading] = useState(true)
  const [executives, setExecutives] = useState<{ id: number; name: string }[]>([])
  const [loanTypes, setLoanTypes] = useState<LoanType[]>([])
  const [cashAccounts, setCashAccounts] = useState<CashAccount[]>([])
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])

  const [salesExecutiveId, setSalesExecutiveId] = useState<number | ''>('')
  const [loanTypeId, setLoanTypeId] = useState<number | ''>('')
  const [status, setStatus] = useState<CommissionStatus | ''>('')

  const [payTarget, setPayTarget] = useState<SalesExecutiveCommission | null>(null)
  const [payForm, setPayForm] = useState<PayForm | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    api.users.marketingExecutives().then(setExecutives).catch(() => setExecutives([]))
    loanTypesApi.list().then((d) => setLoanTypes(d.filter((t) => t.isProduct && Number(t.commissionValue || 0) > 0))).catch(() => setLoanTypes([]))
    cashAccountsApi.list().then(setCashAccounts).catch(() => setCashAccounts([]))
    bankAccountsApi.list().then(setBankAccounts).catch(() => setBankAccounts([]))
  }, [])

  const refresh = () => {
    setLoading(true)
    salesExecutiveCommissionsApi.list({ salesExecutiveId, loanTypeId, status })
      .then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [salesExecutiveId, loanTypeId, status]) // eslint-disable-line react-hooks/exhaustive-deps

  const openPay = (c: SalesExecutiveCommission) => { setPayTarget(c); setPayForm(blankPayForm(c)); setErr('') }
  const closePay = () => { setPayTarget(null); setPayForm(null); setErr('') }

  const savePay = async () => {
    if (!payTarget || !payForm) return
    if (!payForm.paymentDate) { setErr('Payment date is required.'); return }
    if (!payForm.paymentAmount || Number(payForm.paymentAmount) <= 0) { setErr('Payment amount must be greater than zero.'); return }
    if (payForm.paymentMethod === 'Cash' && !payForm.cashAccountId) { setErr('Pick which till this was paid from.'); return }
    if (payForm.paymentMethod !== 'Cash' && !payForm.bankAccountId) { setErr('Pick which bank account this was paid from.'); return }
    if (payForm.paymentMethod === 'Cheque' && !payForm.referenceNo) { setErr('Cheque number is required.'); return }
    setBusy(true); setErr('')
    try {
      await salesExecutiveCommissionsApi.pay(payTarget.id, {
        paymentDate: payForm.paymentDate,
        paymentAmount: payForm.paymentAmount,
        paymentMethod: payForm.paymentMethod,
        cashAccountId: payForm.cashAccountId ? Number(payForm.cashAccountId) : undefined,
        bankAccountId: payForm.bankAccountId ? Number(payForm.bankAccountId) : undefined,
        referenceNo: payForm.referenceNo || undefined,
        notes: payForm.notes || undefined,
      })
      closePay(); refresh()
      notify.success('Commission paid', payTarget.salesExecutiveName)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Payment failed'))
    } finally { setBusy(false) }
  }

  return (
    <div>
      <PageHead crumbs="Loan Management" title="Sales Executive Commissions" icon="target"
        sub={`${rows.length} commission${rows.length === 1 ? '' : 's'}`} />

      <Card style={{ marginBottom: 16 }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
          <Field label="Sales Executive"><Select value={salesExecutiveId} onChange={(e) => setSalesExecutiveId(Number(e.target.value) || '')}>
            <option value="">All executives</option>
            {executives.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </Select></Field>
          <Field label="Loan Type"><Select value={loanTypeId} onChange={(e) => setLoanTypeId(Number(e.target.value) || '')}>
            <option value="">All loan types</option>
            {loanTypes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </Select></Field>
          <Field label="Commission Status"><Select value={status} onChange={(e) => setStatus(e.target.value as any)}>
            <option value="">All statuses</option>
            <option value="Pending">Pending</option>
            <option value="Eligible">Eligible</option>
            <option value="Paid">Paid</option>
          </Select></Field>
        </div>
      </Card>

      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table
          cols={[
            { label: 'Sales Executive' }, { label: 'Loan Number' }, { label: 'Cycle', align: 'center' }, { label: 'Customer' }, { label: 'Loan Type' },
            { label: 'Loan Amount', align: 'right' }, { label: 'Commission', align: 'right' },
            { label: 'Installments', align: 'center' }, { label: 'Commission Status', align: 'center' },
            { label: 'Payment Status', align: 'center' }, { label: '', align: 'right', w: 130 },
          ]}
          rows={rows} empty="No commissions recorded yet."
          render={(c) => <>
            <Td>{c.salesExecutiveName || '—'}</Td>
            <Td>{c.loanCode}</Td>
            <Td align="center">{c.cycleNo}</Td>
            <Td>{c.customerName || '—'}</Td>
            <Td>{c.loanTypeName || '—'}</Td>
            <Td align="right" mono>{money(c.loanAmount)}</Td>
            <Td align="right" mono>{money(c.commissionAmount)}</Td>
            <Td align="center">{c.paidInstallments} / {c.requiredInstallments}</Td>
            <Td align="center"><Badge tone={commissionTone(c.commissionStatus)} dot>{c.commissionStatus}</Badge></Td>
            <Td align="center"><Badge tone={paymentTone(c.paymentStatus)} dot>{c.paymentStatus}</Badge></Td>
            <Td align="right">
              {c.commissionStatus === 'Eligible' && me?.role === 'admin' && (
                <Btn variant="primary" size="sm" icon="check" onClick={() => openPay(c)}>Pay Commission</Btn>
              )}
              {c.commissionStatus === 'Paid' && <span className="t-2" style={{ fontSize: 12 }}>{c.paidAt?.slice(0, 10)}</span>}
            </Td>
          </>} />}
      </Card>

      <Modal open={!!payTarget} onClose={closePay} width={520} title="Pay Commission"
        sub={payTarget ? `${payTarget.salesExecutiveName} — ${payTarget.loanCode} (Cycle ${payTarget.cycleNo})` : undefined}
        footer={<>
          <Btn variant="plain" onClick={closePay}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={savePay} disabled={busy}>{busy ? 'Saving...' : 'Record Payment'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        {payForm && (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Payment Date"><DateInput value={payForm.paymentDate} onChange={(iso) => setPayForm((s) => s && ({ ...s, paymentDate: iso }))} /></Field>
            <Field label="Payment Amount"><Input type="number" step="0.01" value={payForm.paymentAmount} onChange={(e) => setPayForm((s) => s && ({ ...s, paymentAmount: e.target.value }))} /></Field>
            <Field label="Payment Method"><Select value={payForm.paymentMethod} onChange={(e) => setPayForm((s) => s && ({ ...s, paymentMethod: e.target.value as PaymentMethod }))}>
              <option value="Cash">Cash</option><option value="Bank">Bank</option><option value="Cheque">Cheque</option>
            </Select></Field>
            {payForm.paymentMethod === 'Cash' ? (
              <Field label="Cash Till"><Select value={payForm.cashAccountId} onChange={(e) => setPayForm((s) => s && ({ ...s, cashAccountId: Number(e.target.value) || '' }))}>
                <option value="">Select till...</option>
                {cashAccounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </Select></Field>
            ) : (
              <Field label="Bank Account"><Select value={payForm.bankAccountId} onChange={(e) => setPayForm((s) => s && ({ ...s, bankAccountId: Number(e.target.value) || '' }))}>
                <option value="">Select account...</option>
                {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.bank?.name || ''}</option>)}
              </Select></Field>
            )}
            <Field label={payForm.paymentMethod === 'Cheque' ? 'Cheque No.' : 'Reference Number'}><Input value={payForm.referenceNo} onChange={(e) => setPayForm((s) => s && ({ ...s, referenceNo: e.target.value }))} /></Field>
            <Field label="Notes" full><Input value={payForm.notes} onChange={(e) => setPayForm((s) => s && ({ ...s, notes: e.target.value }))} /></Field>
          </div>
        )}
      </Modal>
    </div>
  )
}
