/* Tazz Investment & Credit — Masters (shared lookup/category lists)
   One generic screen renders every master list; each nav item below is just
   this component pre-bound to a `type`. Adding a new master list later is:
   whitelist the type in the backend's MasterCategoryController::TYPES, then
   add one more exported screen here + a nav entry + an App.tsx route. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, inputStyle } from '../components/ui'
import { Icon } from '../components/Icon'
import { masterCategoriesApi, type MasterCategory, type MasterCategoryType, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import type { Go } from './types'

const blankForm = (type: MasterCategoryType): Partial<MasterCategory> => ({ type, status: 'Active', sortOrder: 0 })

function MasterCategoryScreen({ type, label, icon, crumbs, me }: { type: MasterCategoryType; label: string; icon: string; crumbs: string; me?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<MasterCategory[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<MasterCategory | null>(null)
  const [form, setForm] = useState<Partial<MasterCategory>>(blankForm(type))

  const refresh = () => {
    setLoading(true)
    masterCategoriesApi.list(type).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [type])

  const openCreate = () => { setEditing(null); setForm(blankForm(type)); setErr(''); setModal(true) }
  const openEdit = (r: MasterCategory) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await masterCategoriesApi.update(editing.id, form)
      else await masterCategoriesApi.create(form)
      close(); refresh()
      notify.success(editing ? `${label} updated` : `${label} created`, form.name)
    } catch (ex: any) {
      setErr(ex?.response?.data?.message || ex?.response?.data?.errors?.name?.[0] || 'Save failed')
    } finally { setBusy(false) }
  }

  const remove = async (r: MasterCategory) => {
    const ok = await notify.confirm({ title: `Delete "${r.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await masterCategoriesApi.remove(r.id); refresh(); notify.success(`${label} deleted`, r.name) }
    catch (ex: any) { notify.error('Delete failed', ex?.response?.data?.message) }
  }

  return (
    <div>
      <PageHead crumbs={crumbs} title={label} icon={icon} sub={`${rows.length} ${rows.length === 1 ? 'entry' : 'entries'}`}
        actions={<Can user={me} module="Masters" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add {label}</Btn></Can>} />
      <Card pad={0}>
        {loading ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading…</div> :
        <Table cols={[{ label: 'Name' }, { label: 'Code' }, { label: 'Description' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          empty={`No ${label.toLowerCase()} yet`}
          rows={rows}
          render={(r) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{r.name}</Td>
            <Td>{r.code || '—'}</Td>
            <Td>{r.description || '—'}</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Masters" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(r)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Masters" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={480} title={editing ? `Edit ${label}` : `Add ${label}`}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        {err && <div style={{ background: 'var(--bad-dim)', color: 'var(--bad)', border: '1px solid var(--bad)', padding: '8px 12px', borderRadius: 'var(--r-s)', fontSize: 12.5, marginBottom: 14 }}>{err}</div>}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => setForm((s) => ({ ...s, code: e.target.value }))} /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
          <Field label="Description" full><textarea value={form.description || ''} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}

export function ExpenseCategoriesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  return <MasterCategoryScreen type="expense_category" label="Expense Categories" icon="tag" crumbs="Masters" me={me} />
}

export function BanksScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  return <MasterCategoryScreen type="bank" label="Banks" icon="building" crumbs="Masters" me={me} />
}
