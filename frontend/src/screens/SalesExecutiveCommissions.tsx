/* Tazz Investment & Credit — Sales Executive Commissions */
import { useEffect, useState } from 'react'
import { isAdminRole } from '../lib/permissions'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Modal, Table, Td, ErrorBanner , LoadingBlock } from '../components/ui'
import {
  api, loanTypesApi, salesExecutiveCommissionsApi, cashAccountsApi, bankAccountsApi,
  type AuthUser, type LoanType, type SalesExecutiveCommission, type CommissionStatus, type PaymentMethod,
  type CashAccount, type BankAccount,
} from '../api'
import { ExportButtons } from '../components/ExportButtons'
import { exportFlatTableToExcel } from '../lib/export'
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

  const voidPayment = async (c: SalesExecutiveCommission) => {
    const ok = await notify.confirm({ title: `Void this commission payment?`, msg: `Reverses ${money(c.commissionAmount)} paid to ${c.salesExecutiveName} and restores the till/bank balance.`, danger: true, okText: 'Void' })
    if (!ok) return
    try { await salesExecutiveCommissionsApi.void(c.id); refresh(); notify.success('Payment voided') }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

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

  const exportExcel = () => {
    void exportFlatTableToExcel({
      reportTitle: 'Sales Executive Commissions', meta: `Generated ${new Date().toISOString().slice(0, 10)}`,
      columns: [
        { label: 'Sales Executive', key: 'exec' }, { label: 'Loan Number', key: 'loan' }, { label: 'Cycle', key: 'cycle', align: 'center', format: 'number' },
        { label: 'Customer', key: 'customer' }, { label: 'Loan Type', key: 'type' },
        { label: 'Loan Amount', key: 'amount', align: 'right', format: 'money' },
        { label: 'Disbursed', key: 'disbursed', align: 'center' }, { label: 'Closed', key: 'closed', align: 'center' },
        { label: 'Commission', key: 'commission', align: 'right', format: 'money' },
        { label: 'Installments', key: 'inst', align: 'center' }, { label: 'Commission Status', key: 'cStatus', align: 'center' },
        { label: 'Payment Status', key: 'pStatus', align: 'center' }, { label: 'Paid On', key: 'paidAt', align: 'center' },
      ],
      rows: rows.map((c) => ({
        exec: c.salesExecutiveName || '', loan: c.loanCode, cycle: c.cycleNo, customer: c.customerName || '', type: c.loanTypeName || '',
        amount: Number(c.loanAmount || 0), disbursed: c.disbursedAt?.slice(0, 10) || '', closed: c.closedAt?.slice(0, 10) || '',
        commission: Number(c.commissionAmount || 0), inst: `${c.paidInstallments} / ${c.requiredInstallments}`,
        cStatus: c.commissionStatus, pStatus: c.paymentStatus, paidAt: c.paidAt?.slice(0, 10) || '',
      })),
      totalRow: { exec: 'Total', amount: rows.reduce((a, c) => a + Number(c.loanAmount || 0), 0), commission: rows.reduce((a, c) => a + Number(c.commissionAmount || 0), 0) },
      emptyText: 'No commissions',
    }, `sales-executive-commissions-${new Date().toISOString().slice(0, 10)}.xlsx`)
  }

  // No PDF library / server template for this report: print the table from a clean window, user picks "Save as PDF".
  const exportPdf = async () => {
    const esc = (v: unknown) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    const head = ['Sales Executive', 'Loan Number', 'Cycle', 'Customer', 'Loan Type', 'Loan Amount', 'Disbursed', 'Closed', 'Commission', 'Installments', 'Commission Status', 'Payment Status']
    const body = rows.map((c) => [
      c.salesExecutiveName, c.loanCode, c.cycleNo, c.customerName, c.loanTypeName, money(c.loanAmount),
      c.disbursedAt?.slice(0, 10) || '—', c.closedAt?.slice(0, 10) || '—', money(c.commissionAmount),
      `${c.paidInstallments} / ${c.requiredInstallments}`, c.commissionStatus, c.paymentStatus,
    ])
    const w = window.open('', '_blank')
    if (!w) throw new Error('Allow pop-ups to export the PDF.')
    w.document.write(`<!doctype html><html><head><title>Sales Executive Commissions</title><style>
      @page{size:A4 landscape;margin:12mm}body{font:11px Arial,sans-serif;color:#111}h1{font-size:16px;margin:0 0 4px}
      p{margin:0 0 10px;color:#666}table{width:100%;border-collapse:collapse}th{background:#1F3A5F;color:#fff;text-align:left}
      th,td{border:1px solid #d5dce5;padding:4px 6px}tr:nth-child(even) td{background:#f8fafc}</style></head><body>
      <h1>Sales Executive Commissions</h1><p>Generated ${new Date().toISOString().slice(0, 10)} · ${rows.length} record(s)</p>
      <table><thead><tr>${head.map((h) => `<th>${h}</th>`).join('')}</tr></thead>
      <tbody>${body.map((r) => `<tr>${r.map((v) => `<td>${esc(v)}</td>`).join('')}</tr>`).join('')}</tbody></table>
      </body></html>`)
    w.document.close()
    w.focus()
    w.print()
  }

  return (
    <div>
      <PageHead crumbs="Reports / Loan Reports" title="Sales Executive Commissions" icon="target"
        sub={`${rows.length} commission${rows.length === 1 ? '' : 's'}`}
        actions={<ExportButtons onExcel={exportExcel} onPdf={exportPdf} />} />

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
        {loading ? <LoadingBlock /> :
        <Table
          cols={[
            { label: 'Sales Executive' }, { label: 'Loan Number' }, { label: 'Cycle', align: 'center' }, { label: 'Customer' }, { label: 'Loan Type' },
            { label: 'Loan Amount', align: 'right' }, { label: 'Disbursed' }, { label: 'Closed' }, { label: 'Commission', align: 'right' },
            { label: 'Installments', align: 'center' }, { label: 'Commission Status', align: 'center' },
            { label: 'Payment Status', align: 'center' }, { label: '', align: 'right', w: 130 },
          ]}
          rows={rows} empty="No commissions recorded yet."
          render={(c) => <>
            <Td>{c.salesExecutiveName || '—'}{c.staffCode ? <span className="mono" style={{ marginLeft: 6, color: 'var(--tx-2)' }}>{c.staffCode}</span> : null}</Td>
            <Td>{c.loanCode}</Td>
            <Td align="center">{c.cycleNo}</Td>
            <Td>{c.customerName || '—'}</Td>
            <Td>{c.loanTypeName || '—'}</Td>
            <Td align="right" mono>{money(c.loanAmount)}</Td>
            <Td>{c.disbursedAt?.slice(0, 10) || '—'}</Td>
            <Td>{c.closedAt?.slice(0, 10) || '—'}</Td>
            <Td align="right" mono>{money(c.commissionAmount)}</Td>
            <Td align="center">{c.paidInstallments} / {c.requiredInstallments}</Td>
            <Td align="center"><Badge tone={commissionTone(c.commissionStatus)} dot>{c.commissionStatus}</Badge></Td>
            <Td align="center"><Badge tone={paymentTone(c.paymentStatus)} dot>{c.paymentStatus}</Badge></Td>
            <Td align="right">
              {c.commissionStatus === 'Eligible' && isAdminRole(me?.role) && (
                <Btn variant="primary" size="sm" icon="check" onClick={() => openPay(c)}>Pay Commission</Btn>
              )}
              {c.commissionStatus === 'Paid' && (
                <div className="row gap-2" style={{ justifyContent: 'flex-end', alignItems: 'center' }}>
                  <span className="t-2" style={{ fontSize: 12 }}>{c.paidAt?.slice(0, 10)}</span>
                  {isAdminRole(me?.role) && <Btn variant="danger" size="sm" icon="x" onClick={() => voidPayment(c)}>Void</Btn>}
                </div>
              )}
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
