/* Tazz Investment & Credit — Expense Management: Vendors, Expenses, Payment Vouchers.
   A voucher only ever exists as the result of paying one Expense (see
   ExpenseController::pay on the backend) — PaymentVouchersScreen below is a
   view/void screen over that auto-created record, not a place to raise a
   new one from scratch. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Modal, Table, Td, ErrorBanner , LoadingBlock } from '../components/ui'
import {
  vendorsApi, staffApi, expensesApi, expenseCategoriesApi, branchesApi, cashAccountsApi, bankAccountsApi, paymentVouchersApi,
  type Vendor, type StaffOption, type Expense, type ExpenseStatus, type ExpenseCategory, type Branch, type CashAccount, type BankAccount,
  type PaymentMethod, type PaymentVoucher, type VoucherStatus, type AuthUser,
} from '../api'
import { Can, canApprove } from '../lib/permissions'
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
        {loading ? <LoadingBlock /> :
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
  branchId: number | ''; expenseCategoryId: number | ''; vendorId: number | ''; staffId: number | '';
  amount: number | string; expenseDate: string; description: string; backDated: boolean
}
const blankCreateForm = (): CreateForm => ({
  branchId: '', expenseCategoryId: '', vendorId: '', staffId: '', amount: '', expenseDate: new Date().toISOString().slice(0, 10), description: '', backDated: false,
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
  const [staff, setStaff] = useState<StaffOption[]>([])
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
    staffApi.options().then(setStaff).catch(() => setStaff([]))
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
        staffId: createForm.staffId ? Number(createForm.staffId) : undefined,
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
    const reason = await notify.prompt({ title: `Reject ${e.code}?`, msg: 'This expense will not be paid. Give a reason — the requester can resubmit afterwards.', placeholder: 'Reason for rejecting', danger: true, okText: 'Reject' })
    if (reason === null) return
    try { await expensesApi.reject(e.id, reason || undefined); refresh(); notify.success('Expense rejected', e.code) }
    catch (ex: any) { notify.error('Reject failed', getErrorText(ex)) }
  }

  // Approve/reject is maker-checker (approval_types code 'expense_approval') —
  // the requester and anyone who already approved can't sign off.
  const canApproveExpense = (e: Expense) =>
    canApprove(me, 'expense_approval')
    && me?.id !== e.pendingApprovalRequest?.requester?.id
    && me?.id !== e.pendingApprovalRequest?.firstApprover?.id
  const approvalsLeft = (e: Expense) =>
    e.pendingApprovalRequest ? e.pendingApprovalRequest.requiredApprovals - (e.pendingApprovalRequest.firstApprover ? 1 : 0) : 0

  const [payMode, setPayMode] = useState<'direct' | 'request'>('direct')
  const openPay = (e: Expense, mode: 'direct' | 'request') => { setPayTarget(e); setPayMode(mode); setPayForm({ ...blankPayForm(), cashAccountId: cashAccounts.find((a) => a.branchId === e.branchId && a.purpose === 'Expenses' && a.status !== 'Inactive')?.id ?? '' }); setErr('') }
  const closePay = () => { setPayTarget(null); setErr('') }

  const savePay = async () => {
    if (!payTarget) return
    if (payMode === 'direct' && payForm.paymentMethod === 'Cash' && !payForm.cashAccountId) { setErr('Pick which till this was paid from.'); return }
    if (payForm.paymentMethod !== 'Cash' && payMode === 'direct' && !payForm.bankAccountId) { setErr('Pick which bank account this was paid from.'); return }
    if (payForm.paymentMethod === 'Cheque' && !payForm.chequeNo) { setErr('Cheque number is required.'); return }
    setBusy(true); setErr('')
    try {
      if (payMode === 'request') {
        await expensesApi.requestPayment(payTarget.id, {
          paymentMethod: payForm.paymentMethod,
          bankAccountId: payForm.bankAccountId ? Number(payForm.bankAccountId) : undefined,
          chequeNo: payForm.chequeNo || undefined,
        })
        notify.success('Payment requested', 'Sent to Finance for approval and payment')
      } else {
        await expensesApi.pay(payTarget.id, {
          paymentMethod: payForm.paymentMethod,
          cashAccountId: payForm.cashAccountId ? Number(payForm.cashAccountId) : undefined,
          bankAccountId: payForm.bankAccountId ? Number(payForm.bankAccountId) : undefined,
          chequeNo: payForm.chequeNo || undefined,
          paidAt: payForm.paidAt,
        })
        notify.success('Expense paid', payTarget.code)
      }
      closePay(); refresh()
    } catch (ex: any) {
      setErr(getErrorText(ex, payMode === 'request' ? 'Could not send the request' : 'Payment failed'))
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
        {loading ? <LoadingBlock /> :
        <Table
          cols={[{ label: 'Code' }, { label: 'Branch' }, { label: 'Category' }, { label: 'Payee' }, { label: 'Date' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 200 }]}
          empty="No expenses recorded yet" rows={rows}
          render={(e) => <>
            <Td mono>{e.code}</Td>
            <Td>{e.branch?.name || '—'}</Td>
            <Td>{e.category?.name || '—'}</Td>
            <Td>{e.staff ? `${e.staff.name} (${e.staff.staffCode})` : (e.vendor?.name || '—')}</Td>
            <Td>{dateOnly(e.expenseDate)}</Td>
            <Td align="right" mono>{money(e.amount)}</Td>
            <Td align="center">
              <Badge tone={statusTone(e.status)} dot>{e.status}</Badge>
              {e.status === 'Pending' && approvalsLeft(e) > 0 && (
                <div className="t-2" style={{ fontSize: 10.5, marginTop: 2 }}>needs {approvalsLeft(e)} approval{approvalsLeft(e) === 1 ? '' : 's'}</div>
              )}
              {e.status === 'Approved' && e.pendingPaymentRequest && (
                <div className="t-2" style={{ fontSize: 10.5, marginTop: 2 }}>payment requested · {e.pendingPaymentRequest.status.replace(/([A-Z])/g, ' $1').trim()}</div>
              )}
            </Td>
            <Td align="right">
              {e.status === 'Pending' && canApproveExpense(e) && <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                <Btn variant="ghost" size="sm" onClick={() => reject(e)}>Reject</Btn>
                <Btn variant="primary" size="sm" icon="check" onClick={() => approve(e)}>Approve</Btn>
              </div>}
              {e.status === 'Approved' && !e.pendingPaymentRequest && <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                <Can user={me} module="Expenses" action="Create">
                  <Btn variant="ghost" size="sm" icon="receipt" onClick={() => openPay(e, 'request')}>Request Pay</Btn>
                </Can>
                <Can user={me} module="Expenses" action="Edit">
                  <Btn variant="primary" size="sm" icon="wallet" onClick={() => openPay(e, 'direct')}>Pay Now</Btn>
                </Can>
              </div>}
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
            {categories.map((c) => <option key={c.id} value={c.id} disabled={!c.chartOfAccountId}>{c.parent?.name ? `${c.parent.name} › ` : ''}{c.name}{!c.chartOfAccountId ? ' (not linked to GL)' : ''}</option>)}
          </Select></Field>
          <Field label="Vendor (optional)"><Select value={createForm.vendorId} onChange={(e) => setCreateForm((s) => ({ ...s, vendorId: Number(e.target.value) || '', staffId: '' }))}>
            <option value="">No vendor</option>
            {vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </Select></Field>
          <Field label="Staff (optional)"><Select value={createForm.staffId} onChange={(e) => setCreateForm((s) => ({ ...s, staffId: Number(e.target.value) || '', vendorId: '' }))}>
            <option value="">No staff</option>
            {staff.map((p) => <option key={p.id} value={p.id}>{p.staffCode} · {p.name}{p.phone ? ` · ${p.phone}` : ''}</option>)}
          </Select></Field>
          <Field label="Amount"><Input type="number" step="0.01" value={createForm.amount} onChange={(e) => setCreateForm((s) => ({ ...s, amount: e.target.value }))} /></Field>
          <Field label="Expense Date">
            <DateInput value={createForm.expenseDate} disabled={!createForm.backDated} onChange={(iso) => setCreateForm((s) => ({ ...s, expenseDate: iso }))} />
            <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 6 }}>
              <input type="checkbox" checked={createForm.backDated} onChange={(e) => {
                const checked = e.target.checked
                setCreateForm((s) => ({ ...s, backDated: checked, expenseDate: checked ? s.expenseDate : new Date().toISOString().slice(0, 10) }))
              }} />
              <span className="t-2" style={{ fontSize: 11.5 }}>Back Dated Entry</span>
            </label>
          </Field>
          <Field label="Description" full><Input value={createForm.description} onChange={(e) => setCreateForm((s) => ({ ...s, description: e.target.value }))} /></Field>
        </div>
      </Modal>

      <Modal open={!!payTarget} onClose={closePay} width={520}
        title={payMode === 'request' ? 'Request Expense Payment' : 'Pay Expense'}
        sub={payTarget ? `${payTarget.code} — ${money(payTarget.amount)}` : undefined}
        footer={<>
          <Btn variant="plain" onClick={closePay}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={savePay} disabled={busy}>
            {busy ? 'Working…' : payMode === 'request' ? 'Send Request to Finance' : 'Confirm Payment'}
          </Btn>
        </>}>
        <ErrorBanner error={err} />
        {payMode === 'request' && (
          <p className="t-2" style={{ fontSize: 11.5, marginBottom: 12 }}>
            Finance will approve this and choose the till or account it is paid from. Set a preferred method below if it matters.
          </p>
        )}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label={payMode === 'request' ? 'Preferred Method' : 'Payment Method'}><Select value={payForm.paymentMethod} onChange={(e) => setPayForm((s) => ({ ...s, paymentMethod: e.target.value as PaymentMethod }))}>
            <option value="Cash">Cash</option><option value="Bank">Bank</option><option value="Cheque">Cheque</option>
          </Select></Field>
          {payMode === 'direct' && (
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
          )}
          {payMode === 'direct' && payForm.paymentMethod === 'Cash' ? (
            <Field label="Cash Till" full><Select value={payForm.cashAccountId} onChange={(e) => setPayForm((s) => ({ ...s, cashAccountId: Number(e.target.value) || '' }))}>
              <option value="">Select till…</option>
              {cashAccounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </Select></Field>
          ) : payForm.paymentMethod !== 'Cash' ? (
            <>
              <Field label="Bank Account"><Select value={payForm.bankAccountId} onChange={(e) => setPayForm((s) => ({ ...s, bankAccountId: Number(e.target.value) || '' }))}>
                <option value="">Select account…</option>
                {bankAccounts.map((a) => <option key={a.id} value={a.id}>{a.name} — {a.bank?.name || ''}</option>)}
              </Select></Field>
              {payForm.paymentMethod === 'Cheque' && <Field label="Cheque No."><Input value={payForm.chequeNo} onChange={(e) => setPayForm((s) => ({ ...s, chequeNo: e.target.value }))} /></Field>}
            </>
          ) : null}
        </div>
      </Modal>
    </div>
  )
}

/* ---------------------------- Payment Vouchers ---------------------------- */

const voucherTone = (s: VoucherStatus) => s === 'Voided' ? 'red' : 'green'

export function PaymentVouchersScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<PaymentVoucher[]>([])
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState<VoucherStatus | ''>('')

  const refresh = () => {
    setLoading(true)
    paymentVouchersApi.list({ status: status || undefined }).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [status]) // eslint-disable-line react-hooks/exhaustive-deps

  const voidVoucher = async (v: PaymentVoucher) => {
    const reason = await notify.prompt({
      title: `Void voucher ${v.code}?`,
      msg: `This reverses its GL posting, restores ${money(v.amount)} to the ${v.paymentMethod === 'Cash' ? 'till' : 'bank account'} it was paid from, and reopens the expense for payment again.`,
      placeholder: 'Reason for voiding', danger: true, okText: 'Void',
    })
    if (reason === null) return
    try { await paymentVouchersApi.void(v.id, reason || undefined); refresh(); notify.success('Voucher voided', v.code) }
    catch (ex: any) { notify.error('Void failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Expense Management" title="Payment Vouchers" icon="doc" sub={`${rows.length} ${rows.length === 1 ? 'voucher' : 'vouchers'}`} />

      <Card style={{ marginBottom: 16 }}>
        <Field label="Status"><Select value={status} onChange={(e) => setStatus(e.target.value as any)} style={{ maxWidth: 200 }}>
          <option value="">All statuses</option>
          <option value="Issued">Issued</option><option value="Voided">Voided</option>
        </Select></Field>
      </Card>

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table
          cols={[{ label: 'Code' }, { label: 'Expense' }, { label: 'Payee' }, { label: 'Method' }, { label: 'Paid At' }, { label: 'Amount', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          empty="No payment vouchers yet" rows={rows}
          render={(v) => <>
            <Td mono>{v.code}</Td>
            <Td>{v.expense?.code || '—'}</Td>
            <Td>{v.staff ? `${v.staff.name} (${v.staff.staffCode})` : (v.vendor?.name || '—')}</Td>
            <Td>{v.paymentMethod}{v.paymentMethod === 'Cheque' && v.chequeNo ? ` · ${v.chequeNo}` : ''}</Td>
            <Td>{dateOnly(v.paidAt)}</Td>
            <Td align="right" mono>{money(v.amount)}</Td>
            <Td align="center"><Badge tone={voucherTone(v.status)} dot>{v.status}</Badge></Td>
            <Td align="right">
              {v.status === 'Issued' && <Can user={me} module="Payment Vouchers" action="Delete">
                <Btn variant="ghost" size="sm" onClick={() => voidVoucher(v)}>Void</Btn>
              </Can>}
              {v.status === 'Voided' && v.voidReason && <span className="t-2" style={{ fontSize: 12 }} title={v.voidReason}>{v.voider?.name}</span>}
            </Td>
          </>} />}
      </Card>
    </div>
  )
}
