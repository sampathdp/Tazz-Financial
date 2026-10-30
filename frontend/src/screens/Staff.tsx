/* Tazz Investment & Credit — Staff register: system staff code, contact details, optional login.
   Staff are who incentives/commissions are paid to; HR (salary, leave) is out of scope. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, ErrorBanner, LoadingBlock } from '../components/ui'
import { staffApi, branchesApi, rolesApi, type Staff, type Branch, type Role, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const blankForm = (): Partial<Staff> => ({ status: 'Active' })

export function StaffScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<Staff[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [roles, setRoles] = useState<Role[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [search, setSearch] = useState('')
  const [branchFilter, setBranchFilter] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<Staff | null>(null)
  const [form, setForm] = useState<Partial<Staff>>(blankForm())
  const [loginFor, setLoginFor] = useState<Staff | null>(null)
  const [login, setLogin] = useState<{ roleId: number | ''; password: string }>({ roleId: '', password: '' })

  const refresh = () => {
    setLoading(true)
    staffApi.search(search.trim()).then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { const t = setTimeout(refresh, 250); return () => clearTimeout(t) }, [search]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
    rolesApi.list().then(setRoles).catch(() => setRoles([]))
  }, [])

  const openCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setModal(true) }
  const openEdit = (r: Staff) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    setBusy(true); setErr('')
    try {
      const body = {
        name: form.name, nicNo: form.nicNo || null, phone: form.phone || null, email: form.email || null,
        designation: form.designation || null, branchId: form.branchId || null, status: form.status || 'Active',
      }
      const saved = editing ? await staffApi.update(editing.id, body) : await staffApi.create(body)
      close(); refresh()
      notify.success(editing ? 'Staff updated' : 'Staff added', `${saved.staffCode} · ${saved.name}`)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (r: Staff) => {
    const ok = await notify.confirm({ title: `Delete "${r.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await staffApi.remove(r.id); refresh(); notify.success('Staff deleted', r.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const openLogin = (r: Staff) => { setLoginFor(r); setLogin({ roleId: '', password: '' }); setErr('') }
  const saveLogin = async () => {
    if (!loginFor) return
    if (!login.roleId) { setErr('Pick a role.'); return }
    if (!login.password) { setErr('Password is required.'); return }
    setBusy(true); setErr('')
    try {
      await staffApi.createLogin(loginFor.id, { roleId: Number(login.roleId), password: login.password })
      notify.success('Login created', `${loginFor.staffCode} · ${loginFor.email}`)
      setLoginFor(null); refresh()
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Could not create the login'))
    } finally { setBusy(false) }
  }

  const shown = rows.filter((r) => !branchFilter || (branchFilter === 'none' ? !r.branchId : String(r.branchId) === branchFilter))

  return (
    <div>
      <PageHead crumbs="Staff" title="Staff Members" icon="users" sub={`${shown.length} ${shown.length === 1 ? 'member' : 'members'}`}
        actions={<Can user={me} module="Staff" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Staff</Btn></Can>} />
      <Card style={{ marginBottom: 16 }}>
        <div className="row gap-2" style={{ flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <Field label="Search"><Input value={search} placeholder="Staff ID, name, NIC, phone or email" onChange={(e) => setSearch(e.target.value)} style={{ width: 360, maxWidth: '100%' }} /></Field>
          <Field label="Branch"><Select value={branchFilter} onChange={(e) => setBranchFilter(e.target.value)} style={{ minWidth: 200 }}>
            <option value="">All branches</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            <option value="none">No branch</option>
          </Select></Field>
        </div>
      </Card>
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Staff ID' }, { label: 'Name' }, { label: 'NIC' }, { label: 'Phone' }, { label: 'Email' }, { label: 'Branch' }, { label: 'Login', align: 'center' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 190 }]}
          empty="No staff found" rows={shown}
          render={(r) => <>
            <Td mono>{r.staffCode}</Td>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{r.name}{r.designation ? <div style={{ fontWeight: 400, color: 'var(--tx-2)', fontSize: 12 }}>{r.designation}</div> : null}</Td>
            <Td>{r.nicNo || '—'}</Td>
            <Td>{r.phone || '—'}</Td>
            <Td>{r.email || '—'}</Td>
            <Td>{r.branch?.name || '—'}</Td>
            <Td align="center">{r.hasLogin ? <Badge tone="blue">{r.roleName || 'Yes'}</Badge> : <span style={{ color: 'var(--tx-2)' }}>—</span>}</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              {!r.hasLogin && <Can user={me} module="Staff" action="Edit"><Can user={me} module="User Management" action="Create"><Btn variant="ghost" size="sm" onClick={() => openLogin(r)}>Create login</Btn></Can></Can>}
              <Can user={me} module="Staff" action="Edit"><Btn variant="ghost" size="sm" onClick={() => openEdit(r)}>Edit</Btn></Can>
              <Can user={me} module="Staff" action="Delete"><Btn variant="ghost" size="sm" onClick={() => remove(r)}>Delete</Btn></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={560} title={editing ? `Edit Staff · ${editing.staffCode}` : 'Add Staff'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="NIC No."><Input value={form.nicNo || ''} onChange={(e) => setForm((s) => ({ ...s, nicNo: e.target.value }))} /></Field>
          <Field label="Phone"><Input value={form.phone || ''} onChange={(e) => setForm((s) => ({ ...s, phone: e.target.value }))} /></Field>
          <Field label="Email"><Input value={form.email || ''} onChange={(e) => setForm((s) => ({ ...s, email: e.target.value }))} /></Field>
          <Field label="Designation"><Input value={form.designation || ''} onChange={(e) => setForm((s) => ({ ...s, designation: e.target.value }))} /></Field>
          <Field label="Branch"><Select value={form.branchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) || null }))}>
            <option value="">No branch</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
        </div>
      </Modal>

      <Modal open={!!loginFor} onClose={() => setLoginFor(null)} width={460} title={loginFor ? `Create login · ${loginFor.staffCode}` : ''}
        footer={<>
          <Btn variant="plain" onClick={() => setLoginFor(null)}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveLogin} disabled={busy}>{busy ? 'Creating...' : 'Create Login'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gap: 16 }}>
          <Field label="Login name (email)"><Input value={loginFor?.email || ''} disabled /></Field>
          <Field label="Role"><Select value={login.roleId} onChange={(e) => setLogin((s) => ({ ...s, roleId: Number(e.target.value) || '' }))}>
            <option value="">Select role</option>
            {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </Select></Field>
          <Field label="Password"><Input type="password" value={login.password} onChange={(e) => setLogin((s) => ({ ...s, password: e.target.value }))} /></Field>
        </div>
      </Modal>
    </div>
  )
}
