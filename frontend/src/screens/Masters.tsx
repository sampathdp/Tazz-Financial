/* Tazz Investment & Credit — Masters (dedicated per-list screens)
   Each master list (Expense Categories, Banks, ...) is backed by its own
   table/resource — see api.ts — so the two screens below are independent
   components, not a single generic one keyed by type. Adding a new master
   list later is: a table/model/controller on the backend, a resource in
   api.ts, then one more exported screen here + a nav entry + an App.tsx route. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, inputStyle, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { expenseCategoriesApi, banksApi, documentCategoriesApi, chargeCategoriesApi, chartOfAccountsApi, type ExpenseCategory, type Bank, type DocumentCategory, type ChargeCategory, type ChartOfAccount, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const blankExpenseCategoryForm = (): Partial<ExpenseCategory> => ({ status: 'Active', sortOrder: 0 })

export function ExpenseCategoriesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<ExpenseCategory[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<ExpenseCategory | null>(null)
  const [form, setForm] = useState<Partial<ExpenseCategory>>(blankExpenseCategoryForm())
  const [expenseAccounts, setExpenseAccounts] = useState<ChartOfAccount[]>([])

  const refresh = () => {
    setLoading(true)
    expenseCategoriesApi.list().then((list) => {
      // Mains first, each followed by its sub-categories; anything orphaned goes last.
      const mains = list.filter((c) => !c.parentId)
      const ordered = mains.flatMap((mc) => [mc, ...list.filter((c) => c.parentId === mc.id)])
      setRows([...ordered, ...list.filter((c) => c.parentId && !mains.some((mc) => mc.id === c.parentId))])
    }).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])
  useEffect(() => { chartOfAccountsApi.list().then((d) => setExpenseAccounts(d.filter((a) => a.accountType === 'Expense'))).catch(() => setExpenseAccounts([])) }, [])

  const openCreate = () => { setEditing(null); setForm(blankExpenseCategoryForm()); setErr(''); setModal(true) }
  const openEdit = (r: ExpenseCategory) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    if (!form.code) { setErr('Code is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await expenseCategoriesApi.update(editing.id, form)
      else await expenseCategoriesApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Expense Category updated' : 'Expense Category created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (r: ExpenseCategory) => {
    const ok = await notify.confirm({ title: `Delete "${r.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await expenseCategoriesApi.remove(r.id); refresh(); notify.success('Expense Category deleted', r.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Masters" title="Expense Categories" icon="tag" sub={`${rows.length} ${rows.length === 1 ? 'entry' : 'entries'}`}
        actions={<Can user={me} module="Masters" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Expense Category</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Category' }, { label: 'Code' }, { label: 'Posts To' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          empty="No expense categories yet"
          rows={rows}
          render={(r) => <>
            <Td c="var(--tx-0)" style={r.parentId ? { paddingLeft: 28 } : { fontWeight: 700 }}>
              {r.parentId ? <span className="t-2">↳ </span> : null}{r.name}{!r.parentId && <Badge tone="blue">Main</Badge>}
            </Td>
            <Td>{r.code || '—'}</Td>
            <Td>{r.chartOfAccount ? `${r.chartOfAccount.code} ${r.chartOfAccount.name}` : <span style={{ color: 'var(--bad)' }}>Not linked — expenses can't be paid</span>}</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Masters" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(r)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Masters" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={480} title={editing ? 'Edit Expense Category' : 'Add Expense Category'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => setForm((s) => ({ ...s, code: e.target.value }))} /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
          <Field label="Main Category" full>
            <Select value={form.parentId ?? ''} onChange={(e) => setForm((s) => ({ ...s, parentId: Number(e.target.value) || null }))}>
              <option value="">None — this is a main category</option>
              {rows.filter((c) => !c.parentId && c.id !== editing?.id).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
          <Field label="Posts To (GL Account)" full>
            <Select value={form.chartOfAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, chartOfAccountId: Number(e.target.value) || undefined }))}>
              <option value="">Not linked yet — expenses in this category can't be paid</option>
              {expenseAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
            </Select>
          </Field>
          <Field label="Description" full><textarea value={form.description || ''} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}

const blankBankForm = (): Partial<Bank> => ({ status: 'Active', sortOrder: 0 })

/** Banks — same shape/behavior as ExpenseCategoriesScreen above, backed by
 *  its own `banks` table/resource (see api.ts), since it's a first-class
 *  entity other screens (Bank Accounts, Loan Payments/Cheques, Fixed
 *  Deposits) point at via BankSelect, not just an admin-managed list. */
export function BanksScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<Bank[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<Bank | null>(null)
  const [form, setForm] = useState<Partial<Bank>>(blankBankForm())

  const refresh = () => {
    setLoading(true)
    banksApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])

  const openCreate = () => { setEditing(null); setForm(blankBankForm()); setErr(''); setModal(true) }
  const openEdit = (r: Bank) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await banksApi.update(editing.id, form)
      else await banksApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Bank updated' : 'Bank created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (r: Bank) => {
    const ok = await notify.confirm({ title: `Delete "${r.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await banksApi.remove(r.id); refresh(); notify.success('Bank deleted', r.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Masters" title="Banks" icon="building" sub={`${rows.length} ${rows.length === 1 ? 'entry' : 'entries'}`}
        actions={<Can user={me} module="Masters" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Bank</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Name' }, { label: 'Code' }, { label: 'Description' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          empty="No banks yet"
          rows={rows}
          render={(r) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{r.name}</Td>
            <Td>{r.shortCode || '—'}</Td>
            <Td>{r.description || '—'}</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Masters" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(r)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Masters" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={480} title={editing ? 'Edit Bank' : 'Add Bank'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Code"><Input value={form.shortCode || ''} onChange={(e) => setForm((s) => ({ ...s, shortCode: e.target.value }))} /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
          <Field label="Description" full><textarea value={form.description || ''} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}

const blankDocumentCategoryForm = (): Partial<DocumentCategory> => ({ status: 'Active', sortOrder: 0 })

export function DocumentCategoriesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<DocumentCategory[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<DocumentCategory | null>(null)
  const [form, setForm] = useState<Partial<DocumentCategory>>(blankDocumentCategoryForm())

  const refresh = () => {
    setLoading(true)
    documentCategoriesApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])

  const openCreate = () => { setEditing(null); setForm(blankDocumentCategoryForm()); setErr(''); setModal(true) }
  const openEdit = (r: DocumentCategory) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await documentCategoriesApi.update(editing.id, form)
      else await documentCategoriesApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Category updated' : 'Category created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (r: DocumentCategory) => {
    const ok = await notify.confirm({ title: `Delete "${r.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await documentCategoriesApi.remove(r.id); refresh(); notify.success('Category deleted', r.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Masters" title="Document Categories" icon="file-text" sub={`${rows.length} ${rows.length === 1 ? 'entry' : 'entries'}`}
        actions={<Can user={me} module="Masters" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Category</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Name' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          empty="No document categories yet"
          rows={rows}
          render={(r) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{r.name}</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Masters" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(r)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Masters" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={400} title={editing ? 'Edit Category' : 'Add Category'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div className="col gap-3">
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
        </div>
      </Modal>
    </div>
  )
}

const blankChargeCategoryForm = (): Partial<ChargeCategory> => ({ status: 'Active', sortOrder: 0 })

export function ChargeCategoriesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<ChargeCategory[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<ChargeCategory | null>(null)
  const [form, setForm] = useState<Partial<ChargeCategory>>(blankChargeCategoryForm())
  const [incomeAccounts, setIncomeAccounts] = useState<ChartOfAccount[]>([])

  const refresh = () => {
    setLoading(true)
    chargeCategoriesApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])
  useEffect(() => { chartOfAccountsApi.list().then((d) => setIncomeAccounts(d.filter((a) => a.accountType === 'Income'))).catch(() => setIncomeAccounts([])) }, [])

  const openCreate = () => { setEditing(null); setForm(blankChargeCategoryForm()); setErr(''); setModal(true) }
  const openEdit = (r: ChargeCategory) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await chargeCategoriesApi.update(editing.id, form)
      else await chargeCategoriesApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Category updated' : 'Category created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (r: ChargeCategory) => {
    const ok = await notify.confirm({ title: `Delete "${r.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await chargeCategoriesApi.remove(r.id); refresh(); notify.success('Category deleted', r.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Masters / Charge Categories" title="Charge Categories" sub={`${rows.length} ${rows.length === 1 ? 'entry' : 'entries'}`}
        actions={<Can user={me} module="Masters" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Category</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Category Name' }, { label: 'Posts To' }, { label: 'Status', align: 'center' }, { label: 'Sort Order', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          empty="No charge categories found."
          rows={rows}
          render={(r) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{r.name}</Td>
            <Td>{r.chartOfAccount ? `${r.chartOfAccount.code} ${r.chartOfAccount.name}` : <span style={{ color: 'var(--bad)' }}>Not linked — posts to 4020 Loan Charges / Fees</span>}</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="center">{r.sortOrder}</Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Masters" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(r)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Masters" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={400} title={editing ? 'Edit Category' : 'Add Category'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div className="col gap-3">
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Posts To (GL Account)" full>
            <Select value={form.chartOfAccountId ?? ''} onChange={(e) => setForm((s) => ({ ...s, chartOfAccountId: Number(e.target.value) || undefined }))}>
              <option value="">Not linked yet — falls back to 4020 Loan Charges / Fees</option>
              {incomeAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
            </Select>
          </Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
        </div>
      </Modal>
    </div>
  )
}
