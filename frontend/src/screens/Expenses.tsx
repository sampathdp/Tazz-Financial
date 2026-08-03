/* Tazz Investment & Credit — Expense Management: Vendors, Expenses.
   Payment Vouchers has no screen of its own — a voucher only ever exists as
   the result of paying one Expense (see ExpenseController::pay on the
   backend), so its detail is shown inline on the Expenses list rather than
   as a second, separately-browsable resource. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Modal, Table, Td, ErrorBanner } from '../components/ui'
import {
  vendorsApi, expensesApi, expenseCategoriesApi, branchesApi, cashAccountsApi, bankAccountsApi,
  type Vendor, type Expense, type ExpenseStatus, type ExpenseCategory, type Branch, type CashAccount, type BankAccount,
  type PaymentMethod, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const money = (v: number | string | null | undefined) =>
  Number(v ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const statusTone = (s: ExpenseStatus) => s === 'Paid' ? 'green' : s === 'Approved' ? 'blue' : s === 'Rejected' ? 'red' : 'amber'

/* ---------------------------- Vendors ---------------------------- */

const blankVendorForm = (): Partial<Vendor> => ({ status: 'Active' })

export function VendorsScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<Vendor[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<Vendor | null>(null)
  const [form, setForm] = useState<Partial<Vendor>>(blankVendorForm())

  const refresh = () => {
    setLoading(true)
    vendorsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])

  const openCreate = () => { setEditing(null); setForm(blankVendorForm()); setErr(''); setModal(true) }
  const openEdit = (r: Vendor) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    if (!form.code) { setErr('Code is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await vendorsApi.update(editing.id, form)
      else await vendorsApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Vendor updated' : 'Vendor created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (r: Vendor) => {
    const ok = await notify.confirm({ title: `Delete "${r.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await vendorsApi.remove(r.id); refresh(); notify.success('Vendor deleted', r.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Expense Management" title="Vendors" icon="store" sub={`${rows.length} ${rows.length === 1 ? 'vendor' : 'vendors'}`}
        actions={<Can user={me} module="Vendors" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Vendor</Btn></Can>} />
      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Name' }, { label: 'Code' }, { label: 'Contact' }, { label: 'Phone' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          empty="No vendors yet"
          rows={rows}
          render={(r) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{r.name}</Td>
            <Td mono>{r.code}</Td>
            <Td>{r.contactPerson || '—'}</Td>
            <Td>{r.phone || '—'}</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Vendors" action="Edit"><Btn variant="ghost" size="sm" onClick={() => openEdit(r)}>Edit</Btn></Can>
              <Can user={me} module="Vendors" action="Delete"><Btn variant="ghost" size="sm" onClick={() => remove(r)}>Delete</Btn></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={520} title={editing ? 'Edit Vendor' : 'Add Vendor'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => setForm((s) => ({ ...s, code: e.target.value }))} /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
          <Field label="Contact Person"><Input value={form.contactPerson || ''} onChange={(e) => setForm((s) => ({ ...s, contactPerson: e.target.value }))} /></Field>
          <Field label="Phone"><Input value={form.phone || ''} onChange={(e) => setForm((s) => ({ ...s, phone: e.target.value }))} /></Field>
          <Field label="Email"><Input value={form.email || ''} onChange={(e) => setForm((s) => ({ ...s, email: e.target.value }))} /></Field>
          <Field label="Tax No."><Input value={form.taxNo || ''} onChange={(e) => setForm((s) => ({ ...s, taxNo: e.target.value }))} /></Field>
          <Field label="Address" full><textarea value={form.address || ''} onChange={(e) => setForm((s) => ({ ...s, address: e.target.value }))} rows={2} /></Field>
        </div>
      </Modal>
    </div>
  )
}

/* ---------------------------- Expenses ---------------------------- */

interface CreateForm {
  branchId: number | ''; expenseCategoryId: number | ''; vendorId: number | '';
  amount: number | string; expenseDate: string; description: string
}
const blankCreateForm = (): CreateForm => ({
  branchId: '', expenseCategoryId: '', vendorId: '', amount: '', expenseDate: new Date().toISOString().slice(0, 10), description: '',
})

interface PayForm {
  paymentMethod: PaymentMethod; cashAccountId: number | ''; bankAccountId: number | ''; chequeNo: string; paidAt: string; backDated: boolean
}
const todayIso = () => new Date().toISOString().slice(0, 10)
const blankPayForm = (): PayForm => ({ paymentMethod: 'Cash', cashAccountId: '', bankAccountId: '', chequeNo: '', paidAt: todayIso(), backDated: false })

export function ExpensesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<Expense[]>([])
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState<ExpenseStatus | ''>('')

  const [branches, setBranches] = useState<Branch[]>([])
  const [categories, setCategories] = useState<ExpenseCategory[]>([])
  const [vendors, setVendors] = useState<Vendor[]>([])
  const [cashAccounts, setCashAccounts] = useState<CashAccount[]>([])
  const [bankAccounts, setBankAccounts] = useState<BankAccount[]>([])

  const [createModal, setCreateModal] = useState(false)
  const [createForm, setCreateForm] = useState<CreateForm>(blankCreateForm())
  const [payTarget, setPayTarget] = useState<Expense | null>(null)
  const [payForm, setPayForm] = useState<PayForm>(blankPayForm())
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    expenseCategoriesApi.list().then(setCategories).catch(() => setCategories([]))
    vendorsApi.list().then(setVendors).catch(() => setVendors([]))
    cashAccountsApi.list().then(setCashAccounts).catch(() => setCashAccounts([]))
    bankAccountsApi.list().then(setBankAccounts).catch(() => setBankAccounts([]))
  }, [])

  const refresh = () => {
    setLoading(true)
    expensesApi.list({ status: status || undefined }).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [status]) // eslint-disable-line react-hooks/exhaustive-deps

  const openCreate = () => { setCreateForm(blankCreateForm()); setErr(''); setCreateModal(true) }
  const closeCreate = () => { setCreateModal(false); setErr('') }

  const saveCreate = async () => {
    if (!createForm.branchId) { setErr('Branch is required.'); return }
    if (!createForm.expenseCategoryId) { setErr('Expense category is required.'); return }
    if (!createForm.amount || Number(createForm.amount) <= 0) { setErr('Amount must be greater than zero.'); return }
    setBusy(true); setErr('')
    try {
      await expensesApi.create({
        branchId: Number(createForm.branchId), expenseCategoryId: Number(createForm.expenseCategoryId),
        vendorId: createForm.vendorId ? Number(createForm.vendorId) : undefined,
        amount: createForm.amount, expenseDate: createForm.expenseDate, description: createForm.description || undefined,
      })
      closeCreate(); refresh()
      notify.success('Expense recorded', 'Awaiting approval')
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const approve = async (e: Expense) => {
    try { await expensesApi.approve(e.id); refresh(); notify.success('Expense approved', e.code) }
    catch (ex: any) { notify.error('Approve failed', getErrorText(ex)) }
  }
  const reject = async (e: Expense) => {
    const ok = await notify.confirm({ title: `Reject ${e.code}?`, msg: 'This expense will not be paid.', danger: true, okText: 'Reject' })
    if (!ok) return
    try { await expensesApi.reject(e.id); refresh(); notify.success('Expense rejected', e.code) }
    catch (ex: any) { notify.error('Reject failed', getErrorText(ex)) }
  }

  const openPay = (e: Expense) => { setPayTarget(e); setPayForm(blankPayForm()); setErr('') }
  const closePay = () => { setPayTarget(null); setErr('') }

  const savePay = async () => {
    if (!payTarget) return
    if (payForm.paymentMethod === 'Cash' && !payForm.cashAccountId) { setErr('Pick which till this was paid from.'); return }
    if (payForm.paymentMethod !== 'Cash' && !payForm.bankAccountId) { setErr('Pick which bank account this was paid from.'); return }
    if (payForm.paymentMethod === 'Cheque' && !payForm.chequeNo) { setErr('Cheque number is required.'); return }
    setBusy(true); setErr('')
    try {
      await expensesApi.pay(payTarget.id, {
        paymentMethod: payForm.paymentMethod,
        cashAccountId: payForm.cashAccountId ? Number(payForm.cashAccountId) : undefined,
        bankAccountId: payForm.bankAccountId ? Number(payForm.bankAccountId) : undefined,
        chequeNo: payForm.chequeNo || undefined,
        paidAt: payForm.paidAt,
      })
      closePay(); refresh()
      notify.success('Expense paid', payTarget.code)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Payment failed'))
    } finally { setBusy(false) }
  }

  return (
    <div>
      <PageHead crumbs="Expense Management" title="Expenses" icon="wallet" sub={`${rows.length} ${rows.length === 1 ? 'expense' : 'expenses'}`}
        actions={<Can user={me} module="Expenses" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Record Expense</Btn></Can>} />

      <Card style={{ marginBottom: 16 }}>
        <Field label="Status"><Select value={status} onChange={(e) => setStatus(e.target.value as any)} style={{ maxWidth: 200 }}>
          <option value="">All statuses</option>
          <option value="Pending">Pending</option><option value="Approved">Approved</option>
          <option value="Paid">Paid</option><option value="Rejected">Rejected</option>
        </Select></Field>
      </Card>

      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table
          cols={[{ label: 'Code' }, { label: 'Branch' }, { label: 'Category' }, { label: 'Vendor' }, { label: 'Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 200 }]}
          empty="No expenses recorded yet" rows={rows}
          render={(e) => <>
            <Td mono>{e.code}</Td>
            <Td>{e.branch?.name || '—'}</Td>
            <Td>{e.category?.name || '—'}</Td>
            <Td>{e.vendor?.name || '—'}</Td>
            <Td>{dateOnly(e.expenseDate)}</Td>
            <Td align="right" mono>{money(e.amount)}</Td>
            <Td align="center"><Badge tone={statusTone(e.status)} dot>{e.status}</Badge></Td>
            <Td align="right">
              {e.status === 'Pending' && <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                <Can user={me} module="Expenses" action="Approve"><Btn variant="ghost" size="sm" onClick={() => reject(e)}>Reject</Btn></Can>
                <Can user={me} module="Expenses" action="Approve"><Btn variant="primary" size="sm" icon="check" onClick={() => approve(e)}>Approve</Btn></Can>
              </div>}
              {e.status === 'Approved' && <Can user={me} module="Expenses" action="Edit">
                <Btn variant="primary" size="sm" icon="wallet" onClick={() => openPay(e)}>Pay</Btn>
              </Can>}
              {e.status === 'Paid' && e.voucher && <span className="t-2" style={{ fontSize: 12 }}>{e.voucher.paymentMethod} — {dateOnly(e.voucher.paidAt)}</span>}
            </Td>
          </>} />}
      </Card>

      <Modal open={createModal} onClose={closeCreate} width={560} title="Record Expense"
        footer={<>
          <Btn variant="plain" onClick={closeCreate}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveCreate} disabled={busy}>{busy ? 'Saving...' : 'Record'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Branch"><Select value={createForm.branchId} onChange={(e) => setCreateForm((s) => ({ ...s, branchId: Number(e.target.value) || '' }))}>
            <option value="">Select branch…</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Expense Category"><Select value={createForm.expenseCategoryId} onChange={(e) => setCreateForm((s) => ({ ...s, expenseCategoryId: Number(e.target.value) || '' }))}>
            <option value="">Select category…</option>
            {categories.map((c) => <option key={c.id} value={c.id} disabled={!c.accountId}>{c.name}{!c.accountId ? ' (not linked to GL)' : ''}</option>)}
          </Select></Field>
          <Field label="Vendor (optional)"><Select value={createForm.vendorId} onChange={(e) => setCreateForm((s) => ({ ...s, vendorId: Number(e.target.value) || '' }))}>
            <option value="">No vendor</option>
            {vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </Select></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={createForm.amount} onChange={(e) => setCreateForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Expense Date"><DateInput value={createForm.expenseDate} onChange={(iso) => setCreateForm((s) => ({ ...s, expenseDate: iso }))} /></Field>
          <Field label="Description" full><Input value={createForm.description} onChange={(e) => setCreateForm((s) => ({ ...s, description: e.target.value }))} /></Field>
        </div>
      </Modal>

      <Modal open={!!payTarget} onClose={closePay} width={520} title="Pay Expense" sub={payTarget ? `${payTarget.code} — ${money(payTarget.amount)}` : undefined}
        footer={<>
          <Btn variant="plain" onClick={closePay}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={savePay} disabled={busy}>{busy ? 'Paying...' : 'Confirm Payment'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Payment Method"><Select value={payForm.paymentMethod} onChange={(e) => setPayForm((s) => ({ ...s, paymentMethod: e.target.value as PaymentMethod }))}>
            <option value="Cash">Cash</option><option value="Bank">Bank</option><option value="Cheque">Cheque</option>
          </Select></Field>
          <Field label="Paid At">
            <DateInput value={payForm.paidAt} disabled={!payForm.backDated} onChange={(iso) => setPayForm((s) => ({ ...s, paidAt: iso }))} />
            <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
              <input type="checkbox" checked={payForm.backDated} onChange={(e) => {
                const checked = e.target.checked
                setPayForm((s) => ({ ...s, backDated: checked, paidAt: checked ? s.paidAt : todayIso() }))
              }} />
              <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
            </label>
          </Field>
          {payForm.paymentMethod === 'Cash' ? (
            <Field label="Cash Till" full><Select value={payForm.cashAccountId} onChange={(e) => setPayForm((s) => ({ ...s, cashAccountId: Number(e.target.value) || '' }))}>
              <option value="">Select till…</option>
              {cashAccounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </Select></Field>
          ) : (
            <>
              <Field label="Bank Account"><Select value={payForm.bankAccountId} onChange={(e) => setPayForm((s) => ({ ...s, bankAccountId: Number(e.target.value) || '' }))}>
                <option value="">Select account…</option>
                {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.bankName}</option>)}
              </Select></Field>
              {payForm.paymentMethod === 'Cheque' && <Field label="Cheque No."><Input value={payForm.chequeNo} onChange={(e) => setPayForm((s) => ({ ...s, chequeNo: e.target.value }))} /></Field>}
            </>
          )}
        </div>
      </Modal>
    </div>
  )
}
