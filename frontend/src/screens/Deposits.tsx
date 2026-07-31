/* Funding Management — Deposits: cash-in step before a Fixed Deposit exists.
   Record a deposit, print its Deposit Slip, and later allocate it to an FD
   from the Fixed Deposits screen (fdDepositsApi status flips Available -> Allocated). */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, BankSelect, SearchSelect, Modal, Table, Td, inputStyle, ErrorBanner } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  fdDepositsApi, branchesApi, customersApi, company,
  type FdDeposit, type FdPaymentMethod, type Branch, type Customer, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const METHODS: { value: FdPaymentMethod; label: string }[] = [
  { value: 'Cash', label: 'Cash' },
  { value: 'Cheque', label: 'Cheque' },
  { value: 'BankTransfer', label: 'Bank Transfer' },
]
const REF_LABEL: Record<FdPaymentMethod, string> = { Cash: 'Receipt No. (optional)', Cheque: 'Cheque No.', BankTransfer: 'Transfer Reference' }

interface DepositForm {
  branchId?: number; customerId?: number; customerLabel?: string
  depositDate: string; amount: string; paymentMethod: FdPaymentMethod
  referenceNo: string; bankName: string; notes: string
}
const blankForm = (branchId?: number): DepositForm => ({
  branchId, depositDate: todayIso(), amount: '', paymentMethod: 'Cash', referenceNo: '', bankName: '', notes: '',
})

export function DepositsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<FdDeposit[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [customers, setCustomers] = useState<Customer[]>([])
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState<'all' | 'Available' | 'Allocated'>('all')
  const [modal, setModal] = useState(false)
  const [form, setForm] = useState<DepositForm>(blankForm())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [slip, setSlip] = useState<FdDeposit | null>(null)
  const [brand, setBrand] = useState({ name: '', tagline: '' })

  const refresh = () => {
    setLoading(true)
    fdDepositsApi.list(tab === 'all' ? undefined : { status: tab }).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [tab]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    customersApi.list().then(setCustomers).catch(() => setCustomers([]))
    company.branding().then((b) => setBrand({ name: b.name, tagline: b.tagline })).catch(() => {})
  }, [])

  const openCreate = () => { setForm(blankForm(branches[0]?.id)); setErr(''); setModal(true) }
  const close = () => { setModal(false); setErr('') }

  const save = async () => {
    if (!form.branchId) { setErr('Branch is required.'); return }
    if (!form.customerId) { setErr('Select a customer.'); return }
    if (!form.amount || Number(form.amount) <= 0) { setErr('Amount must be greater than zero.'); return }
    if (form.paymentMethod !== 'Cash' && !form.bankName) { setErr('Bank name is required for Cheque/Bank Transfer deposits.'); return }
    setBusy(true); setErr('')
    try {
      const deposit = await fdDepositsApi.create({
        branchId: form.branchId, customerId: form.customerId, depositDate: form.depositDate, amount: form.amount,
        paymentMethod: form.paymentMethod, referenceNo: form.referenceNo || undefined,
        bankName: form.paymentMethod !== 'Cash' ? form.bankName : undefined, notes: form.notes || undefined,
      })
      close(); refresh()
      setSlip(deposit)
      notify.success('Deposit recorded', deposit.depositNumber)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const reprint = async (d: FdDeposit) => {
    try { setSlip(await fdDepositsApi.get(d.id)) } catch { setSlip(d) }
  }

  const remove = async (d: FdDeposit) => {
    const ok = await notify.confirm({ title: `Delete deposit "${d.depositNumber}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await fdDepositsApi.remove(d.id); refresh(); notify.success('Deposit deleted', d.depositNumber) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  if (slip) {
    return (
      <div>
        <div className="row gap-2 no-print" style={{ marginBottom: 16 }}>
          <Btn variant="primary" icon="print" onClick={() => window.print()}>Print Deposit Slip</Btn>
          <Btn variant="solid" icon="x" onClick={() => setSlip(null)}>Close</Btn>
        </div>
        <Card style={{ maxWidth: 560, margin: '0 auto', fontFamily: 'Outfit' }}>
          <div className="col gap-1" style={{ textAlign: 'center', marginBottom: 18, paddingBottom: 18, borderBottom: '1px dashed var(--line)' }}>
            <div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 20 }}>{brand.name || 'Company'}</div>
            {brand.tagline && <div className="t-2" style={{ fontSize: 12 }}>{brand.tagline}</div>}
            <div style={{ fontWeight: 700, fontSize: 14, letterSpacing: '.08em', marginTop: 8 }}>DEPOSIT SLIP</div>
          </div>
          <div className="row between" style={{ marginBottom: 14, fontSize: 13 }}>
            <div><span className="t-2">Deposit No: </span><b>{slip.depositNumber}</b></div>
            <div><span className="t-2">Date: </span><b>{dateOnly(slip.depositDate)}</b></div>
          </div>
          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Received From</span><b>{slip.customer?.fullName || '—'}</b></div>
            <div className="row between"><span className="t-2">NIC No</span><b>{slip.customer?.nicNo || '—'}</b></div>
            <div className="row between"><span className="t-2">Branch</span><b>{slip.branch?.name || '—'}</b></div>
          </div>
          <div className="col gap-2" style={{ fontSize: 13, marginBottom: 16, paddingBottom: 16, borderBottom: '1px solid var(--line)' }}>
            <div className="row between"><span className="t-2">Payment Method</span><b>{METHODS.find((m) => m.value === slip.paymentMethod)?.label || slip.paymentMethod}</b></div>
            {slip.referenceNo && <div className="row between"><span className="t-2">{REF_LABEL[slip.paymentMethod]}</span><b>{slip.referenceNo}</b></div>}
            {slip.bankName && <div className="row between"><span className="t-2">Bank</span><b>{slip.bankName}</b></div>}
            {slip.notes && <div className="row between"><span className="t-2">Notes</span><b>{slip.notes}</b></div>}
            <div className="row between"><span className="t-2">Status</span><b>{slip.fixedDeposit ? `Allocated — ${slip.fixedDeposit.fdNumber}` : 'Available for a Fixed Deposit'}</b></div>
          </div>
          <div className="row between" style={{ fontSize: 15, fontWeight: 700 }}>
            <span>Amount Received</span><span style={{ fontFamily: 'JetBrains Mono' }}>{money(slip.amount)}</span>
          </div>
          <div className="row between" style={{ fontSize: 12, marginTop: 30 }}>
            <div>Received By: <b>{slip.creator?.name || me?.name || '—'}</b></div>
            <div>Signature: ______________</div>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHead crumbs="Funding / Investment" title="Deposits" icon="wallet" sub={`${rows.length} deposits`}
        actions={<Can user={me} module="Deposits" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Record Deposit</Btn></Can>} />

      <Card pad={0}>
        <div className="row gap-1" style={{ padding: '14px 16px 0' }}>
          {([['all', 'All'], ['Available', 'Available'], ['Allocated', 'Allocated']] as const).map(([id, label]) => (
            <Btn key={id} size="sm" variant="ghost" active={tab === id} onClick={() => setTab(id)}>{label}</Btn>
          ))}
        </div>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Deposit No' }, { label: 'Customer' }, { label: 'Date' }, { label: 'Method' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 110 }]}
          rows={rows}
          render={(d) => <>
            <Td mono>{d.depositNumber}</Td>
            <Td>{d.customer?.fullName || '—'}</Td>
            <Td>{dateOnly(d.depositDate)}</Td>
            <Td><Badge tone="neutral">{METHODS.find((m) => m.value === d.paymentMethod)?.label || d.paymentMethod}</Badge></Td>
            <Td align="right" mono>{money(d.amount)}</Td>
            <Td align="center"><Badge tone={d.status === 'Available' ? 'amber' : 'green'} dot>{d.status}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <button className="mms-act" title="Print Slip" onClick={() => reprint(d)}><Icon n="print" s={15} /></button>
              {d.status === 'Available' && (
                <Can user={me} module="Deposits" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(d)}><Icon n="trash" s={15} /></button></Can>
              )}
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={560} title="Record Deposit"
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : 'Record & Print Slip'}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Customer" full>
            <SearchSelect
              value={form.customerLabel || ''}
              onChange={(name, opt: Customer | undefined) => setForm((s) => ({ ...s, customerId: opt?.id, customerLabel: name }))}
              options={customers}
              getLabel={(c: Customer) => `${c.code} — ${c.fullName}`}
              getSub={(c: Customer) => [c.nicNo, c.phone].filter(Boolean).join(' · ')}
              placeholder="Search by NIC, code, or name…"
            />
          </Field>
          <Field label="Branch"><Select value={form.branchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Date"><DateInput value={form.depositDate} onChange={(iso) => setForm((s) => ({ ...s, depositDate: iso }))} /></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={form.amount} onChange={(e) => setForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Payment Method"><Select value={form.paymentMethod} onChange={(e) => setForm((s) => ({ ...s, paymentMethod: e.target.value as FdPaymentMethod }))}>
            {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </Select></Field>
          <Field label={REF_LABEL[form.paymentMethod]}><Input value={form.referenceNo} onChange={(e) => setForm((s) => ({ ...s, referenceNo: e.target.value }))} /></Field>
          {form.paymentMethod !== 'Cash' && (
            <Field label="Bank Name"><BankSelect value={form.bankName} onChange={(v) => setForm((s) => ({ ...s, bankName: v }))} /></Field>
          )}
          <Field label="Notes" full><textarea value={form.notes} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}

export default DepositsScreen
