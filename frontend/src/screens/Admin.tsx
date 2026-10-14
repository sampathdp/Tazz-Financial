/* Tazz Investment & Credit — Administration (API-backed) */
import { useEffect, useRef, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, inputStyle, ErrorBanner , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { api, company as companyApi, permissionsApi, branchesApi, rolesApi, type PermissionMatrix, type Branch, type Role, type AuthUser } from '../api'
import { Can, userCan } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

type Grid = Record<string, Record<string, boolean>>

/* ============================== Branches ============================== */

interface BranchForm extends Partial<Branch> {}
const blankBranchForm = (): BranchForm => ({ status: 'Active', isHeadOffice: false, branchType: 'Branch' })

export function BranchesScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<Branch[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<Branch | null>(null)
  const [form, setForm] = useState<BranchForm>(blankBranchForm())

  const refresh = () => {
    setLoading(true)
    branchesApi.list().then((d) => setRows(d)).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [])

  const openCreate = () => { setEditing(null); setForm(blankBranchForm()); setErr(''); setModal(true) }
  const openEdit = (b: Branch) => { setEditing(b); setForm({ ...b }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name || !form.code) { setErr('Name and code are required.'); return }
    if (form.branchType === 'Center' && !form.parentId) { setErr('A center must be linked to a parent branch.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await branchesApi.update(editing.id, form)
      else await branchesApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Branch updated' : 'Branch created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (b: Branch) => {
    const ok = await notify.confirm({ title: `Delete branch "${b.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await branchesApi.remove(b.id); refresh(); notify.success('Branch deleted', b.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Administration" title="Branch Management" icon="building" sub={`${rows.length} branches`}
        actions={<Can user={me} module="Branch Management" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Branch</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Branch' }, { label: 'Code' }, { label: 'Type' }, { label: 'Phone' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 100 }]}
          rows={rows}
          render={(b) => <>
            <Td c="var(--tx-0)"><div style={{ fontWeight: 600 }}>{b.name}{b.isHeadOffice && <Badge tone="blue" dot>Head Office</Badge>}</div><div className="t-2" style={{ fontSize: 11.5 }}>{b.address || '—'}</div></Td>
            <Td mono>{b.code}</Td>
            <Td><Badge tone={b.branchType === 'Center' ? 'amber' : 'neutral'}>{b.branchType || 'Branch'}</Badge>{b.branchType === 'Center' && <div className="t-2" style={{ fontSize: 11, marginTop: 3 }}>under {b.parent?.name || '—'}</div>}</Td>
            <Td>{b.phone || '—'}</Td>
            <Td align="center"><Badge tone={(b.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{b.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Branch Management" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(b)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Branch Management" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(b)} disabled={b.isHeadOffice} style={{ opacity: b.isHeadOffice ? 0.4 : 1 }}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={520} title={editing ? 'Edit Branch' : 'Add Branch'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create Branch')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Branch Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => setForm((s) => ({ ...s, code: e.target.value.toUpperCase() }))} disabled={!!editing?.isHeadOffice} /></Field>
          <Field label="Type"><Select value={form.branchType || 'Branch'} disabled={!!editing?.isHeadOffice}
            onChange={(e) => setForm((s) => ({ ...s, branchType: e.target.value as any, parentId: e.target.value === 'Branch' ? null : s.parentId }))}>
            <option>Branch</option><option>Center</option>
          </Select></Field>
          {form.branchType === 'Center' && (
            <Field label="Parent Branch" full><Select value={form.parentId ?? ''} onChange={(e) => setForm((s) => ({ ...s, parentId: Number(e.target.value) || undefined }))}>
              <option value="">—</option>
              {rows.filter((r) => r.branchType !== 'Center' && r.id !== editing?.id).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </Select></Field>
          )}
          <Field label="Phone"><Input value={form.phone || ''} onChange={(e) => setForm((s) => ({ ...s, phone: e.target.value }))} /></Field>
          <Field label="Email"><Input type="email" value={form.email || ''} onChange={(e) => setForm((s) => ({ ...s, email: e.target.value }))} /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
          <Field label="Address" full><textarea value={form.address || ''} onChange={(e) => setForm((s) => ({ ...s, address: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}

/* =============================== Roles ================================= */

function PermGridEditor({ grid, actions, modules, onToggle, onBulkRow, readOnly }: {
  grid: Grid; actions: string[]; modules: string[]
  onToggle: (m: string, a: string) => void
  onBulkRow: (m: string, val: boolean) => void
  readOnly?: boolean
}) {
  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
        <thead><tr>
          <th style={{ textAlign: 'left', padding: '11px 18px', fontSize: 11, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.06em', borderBottom: '1px solid var(--line)' }}>Module</th>
          {actions.map((a) => <th key={a} style={{ padding: '11px 14px', fontSize: 11, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid var(--line)', width: 84 }}>{a}</th>)}
          <th style={{ padding: '11px 14px', fontSize: 11, color: 'var(--tx-2)', textTransform: 'uppercase', borderBottom: '1px solid var(--line)', width: 84 }}>All</th>
        </tr></thead>
        <tbody>{modules.map((m) => {
          const rowVals = actions.map((a) => grid[m]?.[a])
          const allOn = rowVals.every(Boolean)
          return (
            <tr key={m} className="mms-tr">
              <td style={{ padding: '10px 18px', borderBottom: '1px solid var(--line-soft)', fontWeight: 600, color: 'var(--tx-0)' }}>{m}</td>
              {actions.map((a) => {
                const on = !!grid[m]?.[a]
                return (
                  <td key={a} style={{ padding: '8px 14px', borderBottom: '1px solid var(--line-soft)', textAlign: 'center' }}>
                    <button onClick={() => onToggle(m, a)} disabled={readOnly} style={{ width: 34, height: 20, borderRadius: 20, border: 'none', cursor: readOnly ? 'not-allowed' : 'pointer', background: on ? 'var(--ac)' : 'var(--bg-3)', position: 'relative', transition: 'background .18s', opacity: readOnly ? 0.6 : 1 }}>
                      <span style={{ position: 'absolute', top: 2, left: on ? 16 : 2, width: 16, height: 16, borderRadius: '50%', background: '#fff', transition: 'left .18s' }} />
                    </button>
                  </td>
                )
              })}
              <td style={{ padding: '8px 14px', borderBottom: '1px solid var(--line-soft)', textAlign: 'center' }}>
                <Btn variant="ghost" size="sm" disabled={readOnly} onClick={() => onBulkRow(m, !allOn)}>{allOn ? 'None' : 'All'}</Btn>
              </td>
            </tr>
          )
        })}</tbody>
      </table>
    </div>
  )
}

export function RolesScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const isAdmin = me?.role === 'admin'
  const canEditGrid = userCan(me, 'Role Management', 'Edit')

  const [roles, setRoles] = useState<Role[]>([])
  const [loading, setLoading] = useState(true)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [grid, setGrid] = useState<Grid>({})
  const [modules, setModules] = useState<string[]>([])
  const [actions, setActions] = useState<string[]>([])
  const [gridLoading, setGridLoading] = useState(false)
  const [dirty, setDirty] = useState(false)
  const [saving, setSaving] = useState(false)

  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<Role | null>(null)
  const [form, setForm] = useState<Partial<Role>>({})
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  const refresh = () => {
    setLoading(true)
    rolesApi.list()
      .then((rs) => { setRoles(rs); setSelectedId((cur) => (cur && rs.some((r) => r.id === cur)) ? cur : (rs[0]?.id ?? null)) })
      .catch(() => setRoles([]))
      .finally(() => setLoading(false))
  }
  useEffect(() => { refresh() }, [])

  const loadGrid = (roleId: number) => {
    setGridLoading(true)
    permissionsApi.roleGrid(roleId)
      .then((d) => { setModules(d.modules); setActions(d.actions); setGrid(d.matrix); setDirty(false) })
      .finally(() => setGridLoading(false))
  }
  useEffect(() => { if (selectedId != null) loadGrid(selectedId) }, [selectedId]) // eslint-disable-line react-hooks/exhaustive-deps

  const selectedRole = roles.find((r) => r.id === selectedId) || null
  const readOnly = !canEditGrid || !!selectedRole?.isSystem

  const toggle = (m: string, a: string) => { if (readOnly) return; setGrid((g) => ({ ...g, [m]: { ...g[m], [a]: !g[m]?.[a] } })); setDirty(true) }
  const bulkRow = (m: string, val: boolean) => {
    if (readOnly) return
    const row: Record<string, boolean> = {}
    actions.forEach((a) => (row[a] = val))
    setGrid((g) => ({ ...g, [m]: row })); setDirty(true)
  }
  const save = async () => {
    if (!selectedId) return
    setSaving(true)
    try { await permissionsApi.updateRole(selectedId, grid); setDirty(false); notify.success('Role permissions saved') }
    catch (ex: any) { notify.error('Save failed', getErrorText(ex)) }
    finally { setSaving(false) }
  }

  const openCreate = () => { setEditing(null); setForm({ canViewAllBranches: false }); setErr(''); setModal(true) }
  const openEdit = (r: Role) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }
  const saveRole = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await rolesApi.update(editing.id, { name: form.name, description: form.description || undefined, canViewAllBranches: !!form.canViewAllBranches })
      else await rolesApi.create({ name: form.name, description: form.description || undefined, canViewAllBranches: !!form.canViewAllBranches })
      close(); refresh()
      notify.success(editing ? 'Role updated' : 'Role created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }
  const removeRole = async (r: Role) => {
    const ok = await notify.confirm({ title: `Delete role "${r.name}"?`, danger: true, okText: 'Delete' })
    if (!ok) return
    try {
      await rolesApi.remove(r.id)
      if (selectedId === r.id) setSelectedId(null)
      refresh()
      notify.success('Role deleted', r.name)
    } catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Administration" title="Role Management" icon="shield" sub={`${roles.length} roles`}
        actions={isAdmin && <Btn variant="primary" icon="plus" onClick={openCreate}>Add Role</Btn>} />

      <div style={{ display: 'grid', gridTemplateColumns: '280px 1fr', gap: 16, alignItems: 'start' }}>
        <Card pad={0}>
          {loading ? <LoadingBlock /> : roles.map((r) => (
            <div key={r.id} onClick={() => setSelectedId(r.id)} data-testid="role-row"
              className="row between" style={{ padding: '12px 16px', cursor: 'pointer', borderBottom: '1px solid var(--line-soft)', background: selectedId === r.id ? 'var(--ac-dim)' : 'transparent' }}>
              <div>
                <div style={{ fontWeight: 600, fontSize: 13, color: selectedId === r.id ? 'var(--ac-bright)' : 'var(--tx-0)' }}>{r.name}</div>
                <div className="t-2" style={{ fontSize: 11 }}>{r.usersCount ?? 0} user{(r.usersCount ?? 0) === 1 ? '' : 's'}</div>
              </div>
              {r.isSystem && <Badge tone="blue">System</Badge>}
            </div>
          ))}
        </Card>

        <Card pad={0}>
          {!selectedRole ? <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Select a role</div> : <>
            <div className="row between" style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
              <div>
                <div style={{ fontWeight: 700, fontSize: 15 }}>{selectedRole.name}</div>
                {selectedRole.description && <div className="t-2" style={{ fontSize: 12 }}>{selectedRole.description}</div>}
              </div>
              <div className="row gap-2">
                {isAdmin && !selectedRole.isSystem && <>
                  <Btn variant="solid" size="sm" icon="edit" onClick={() => openEdit(selectedRole)}>Edit</Btn>
                  <Btn variant="danger" size="sm" icon="trash" onClick={() => removeRole(selectedRole)}>Delete</Btn>
                </>}
                <Btn variant="primary" icon="check" onClick={save} disabled={saving || !dirty || readOnly}>{saving ? 'Saving...' : dirty ? 'Save Permissions' : 'Saved'}</Btn>
              </div>
            </div>
            {selectedRole.isSystem && (
              <div style={{ padding: '10px 18px', background: 'var(--ac-dim)', color: 'var(--ac-bright)', fontSize: 12.5, borderBottom: '1px solid var(--ac-line)' }}>
                System roles have implicit full access and cannot be modified.
              </div>
            )}
            {gridLoading ? <LoadingBlock /> :
              <PermGridEditor grid={grid} actions={actions} modules={modules} onToggle={toggle} onBulkRow={bulkRow} readOnly={readOnly} />}
          </>}
        </Card>
      </div>

      <Modal open={modal} onClose={close} width={480} title={editing ? 'Edit Role' : 'Add Role'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveRole} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create Role')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div className="col gap-4">
          <Field label="Role Name"><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Description"><Input value={form.description || ''} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} /></Field>
          <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer' }}>
            <input type="checkbox" checked={!!form.canViewAllBranches} onChange={(e) => setForm((s) => ({ ...s, canViewAllBranches: e.target.checked }))} />
            <span style={{ fontSize: 13 }}>Can view data across all branches (not confined to one branch)</span>
          </label>
        </div>
      </Modal>
    </div>
  )
}

/* =============================== Users ================================= */

interface UserRow { id: number; name: string; email: string; role?: string; roleId?: number; roleName?: string; branchId?: number | null; branch?: { id: number; name: string; code: string } | null; status?: string }
const blankUserForm = (): Partial<UserRow & { password: string }> => ({ status: 'Active' })

export function UsersScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<UserRow[]>([])
  const [roles, setRoles] = useState<Role[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<UserRow | null>(null)
  const [form, setForm] = useState<Partial<UserRow & { password: string }>>(blankUserForm())

  const refresh = () => {
    setLoading(true)
    api.users.list().then((d) => setRows(d as any)).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => {
    refresh()
    rolesApi.list().then(setRoles).catch(() => setRoles([]))
    branchesApi.list().then(setBranches).catch(() => setBranches([]))
  }, [])

  const roleLabel = (roleId?: number | null) => roles.find((r) => r.id === roleId)?.name || '—'
  const roleOf = (roleId?: number | null) => roles.find((r) => r.id === roleId)
  const roleTone = (roleId?: number | null): 'blue' | 'neutral' | 'green' => roleOf(roleId)?.isSystem ? 'blue' : (roleOf(roleId)?.canViewAllBranches ? 'green' : 'neutral')
  const selectedRoleNeedsBranch = (roleId?: number) => roleId != null && !roleOf(roleId)?.canViewAllBranches

  const openCreate = () => { setEditing(null); setForm(blankUserForm()); setErr(''); setModal(true) }
  const openEdit = (u: UserRow) => { setEditing(u); setForm({ ...u, password: '' }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name || !form.email) { setErr('Name and email are required.'); return }
    if (!editing && !form.password) { setErr('Password is required for new users.'); return }
    if (!form.roleId) { setErr('Role is required.'); return }
    if (selectedRoleNeedsBranch(form.roleId) && !form.branchId) { setErr('A branch is required for this role.'); return }
    setBusy(true); setErr('')
    try {
      const body: any = { name: form.name, email: form.email, roleId: form.roleId, branchId: form.branchId || null, status: form.status }
      if (form.password) body.password = form.password
      if (editing) await api.users.update(editing.id, body)
      else await api.users.create(body)
      close(); refresh()
      notify.success(editing ? 'User updated' : 'User created', form.name || undefined)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (u: UserRow) => {
    if (u.id === me?.id) { notify.error('Cannot delete', 'You cannot delete your own account.'); return }
    const ok = await notify.confirm({ title: `Delete user "${u.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await api.users.remove(u.id); refresh(); notify.success('User deleted', u.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Administration" title="User Management" icon="users" sub={`${rows.length} system users`}
        actions={<Can user={me} module="User Management" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add User</Btn></Can>} />
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'User' }, { label: 'Email' }, { label: 'Role', align: 'center' }, { label: 'Branch' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 120 }]}
          rows={rows}
          render={(u) => <>
            <Td c="var(--tx-0)"><div className="row gap-2"><div style={{ width: 30, height: 30, borderRadius: '50%', background: 'var(--bg-3)', display: 'grid', placeItems: 'center', fontFamily: 'Saira', fontWeight: 700, fontSize: 12, color: 'var(--ac-bright)' }}>{(u.name || '?').split(' ').map((w: string) => w[0]).join('').slice(0, 2)}</div><span style={{ fontWeight: 600 }}>{u.name}</span></div></Td>
            <Td mono c="var(--tx-2)" style={{ fontSize: 12 }}>{u.email}</Td>
            <Td align="center"><Badge tone={roleTone(u.roleId)}>{u.roleName || roleLabel(u.roleId)}</Badge></Td>
            <Td>{u.branch?.name || '—'}</Td>
            <Td align="center"><Badge tone={(u.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{u.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="User Management" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(u)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="User Management" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(u)} disabled={u.id === me?.id} style={{ opacity: u.id === me?.id ? 0.4 : 1 }}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={560} title={editing ? 'Edit User' : 'Add System User'}
        sub={editing ? editing.email : 'The role determines the starting permission set — fine-tune per user under User Permission.'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create User')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Full Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></Field>
          <Field label="Email"><Input type="email" value={form.email || ''} onChange={(e) => setForm((s) => ({ ...s, email: e.target.value }))} /></Field>
          <Field label="Role"><Select value={form.roleId ?? ''} onChange={(e) => setForm((s) => ({ ...s, roleId: Number(e.target.value) || undefined }))}>
            <option value="">—</option>
            {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </Select></Field>
          <Field label="Branch"><Select value={form.branchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))} disabled={!selectedRoleNeedsBranch(form.roleId) && !!form.roleId}>
            <option value="">—</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value }))}><option>Active</option><option>Inactive</option></Select></Field>
          <Field label={editing ? 'New Password (min 10 characters; leave blank to keep)' : 'Password (min 10 characters)'}><Input type="password" value={form.password || ''} onChange={(e) => setForm((s) => ({ ...s, password: e.target.value }))} /></Field>
        </div>
      </Modal>
    </div>
  )
}

/* ============================ Permissions ============================== */

export function PermScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [matrix, setMatrix] = useState<PermissionMatrix | null>(null)
  const [loading, setLoading] = useState(true)
  const [userId, setUserId] = useState<number | null>(null)
  const [saving, setSaving] = useState(false)
  const [resetting, setResetting] = useState(false)
  /** Per-user, uncommitted cell toggles: {userId: {module: {action: bool}}}. Only entries the admin actually touched this session — never the full grid, so untouched cells never turn into spurious overrides. */
  const [pending, setPending] = useState<Record<string, Record<string, Record<string, boolean>>>>({})
  const [msg, setMsg] = useState<{ tone: 'ok' | 'err'; text: string } | null>(null)

  const canEdit = userCan(me, 'User Permission', 'Edit')

  const load = () => {
    setLoading(true); setMsg(null)
    permissionsApi.list()
      .then((d) => {
        setMatrix(d)
        setUserId((cur) => (cur && d.users.some((u) => u.id === cur)) ? cur : (d.users[0]?.id ?? null))
        setPending({})
      })
      .catch(() => setMsg({ tone: 'err', text: 'Failed to load permission matrix.' }))
      .finally(() => setLoading(false))
  }
  useEffect(load, []) // eslint-disable-line react-hooks/exhaustive-deps

  const key = userId != null ? String(userId) : ''
  const selectedUser = matrix?.users.find((u) => u.id === userId) || null
  const isAdminUser = selectedUser?.role === 'admin'
  const baseGrid = matrix?.matrix[key] || {}
  const baseOverrides = matrix?.overrides[key] || {}
  const userPending = pending[key] || {}
  const dirty = Object.keys(userPending).length > 0

  const effective = (m: string, a: string): boolean => userPending[m]?.[a] ?? !!baseGrid[m]?.[a]
  const isOverridden = (m: string, a: string): boolean => (userPending[m]?.[a] !== undefined) || !!baseOverrides[m]?.[a]

  const toggle = (m: string, a: string) => {
    if (!matrix || !key || isAdminUser || !canEdit) return
    const next = !effective(m, a)
    setPending((p) => ({ ...p, [key]: { ...p[key], [m]: { ...p[key]?.[m], [a]: next } } }))
    setMsg(null)
  }
  const bulkRow = (m: string, val: boolean) => {
    if (!matrix || !key || isAdminUser || !canEdit) return
    const row: Record<string, boolean> = {}
    matrix.actions.forEach((a) => (row[a] = val))
    setPending((p) => ({ ...p, [key]: { ...p[key], [m]: row } }))
    setMsg(null)
  }

  const save = async () => {
    if (!matrix || !userId || isAdminUser || !dirty) return
    setSaving(true); setMsg(null)
    try {
      await permissionsApi.updateUser(userId, userPending)
      setPending((p) => ({ ...p, [key]: {} }))
      load()
      setMsg({ tone: 'ok', text: 'Permissions saved.' })
    } catch (ex: any) {
      setMsg({ tone: 'err', text: getErrorText(ex, 'Save failed') })
    } finally { setSaving(false) }
  }

  const resetRow = async (m: string) => {
    if (!matrix || !userId || isAdminUser || !canEdit) return
    setResetting(true)
    try {
      const clear: Record<string, null> = {}
      matrix.actions.forEach((a) => (clear[a] = null))
      await permissionsApi.updateUser(userId, { [m]: clear as any })
      setPending((p) => { const np = { ...p[key] }; delete np[m]; return { ...p, [key]: np } })
      load()
    } catch (ex: any) {
      notify.error('Reset failed', getErrorText(ex))
    } finally { setResetting(false) }
  }

  if (loading || !matrix) return <div style={{ padding: 40, textAlign: 'center', color: 'var(--tx-2)' }}>Loading permissions…</div>

  return (
    <div>
      <PageHead crumbs="Administration" title="User Permission" icon="shield" sub="Per-user overrides on top of each role's default access"
        actions={
          <Btn variant="primary" icon="check" onClick={save} disabled={saving || !dirty || isAdminUser || !canEdit}>
            {saving ? 'Saving...' : dirty ? 'Save Permissions' : 'Saved'}
          </Btn>
        } />

      {msg && (
        <div style={{ padding: '10px 14px', marginBottom: 12, borderRadius: 'var(--r-s)', fontSize: 13,
          background: msg.tone === 'ok' ? 'var(--ok-dim)' : 'var(--bad-dim)',
          color: msg.tone === 'ok' ? 'var(--ok)' : 'var(--bad)',
          border: '1px solid ' + (msg.tone === 'ok' ? 'var(--ok)' : 'var(--bad)') }}>{msg.text}</div>
      )}

      <Card pad={0}>
        <div className="row gap-2" style={{ padding: '14px 18px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
          <span className="t-2" style={{ fontSize: 12.5, marginRight: 6 }}>User:</span>
          <Select
            value={userId ?? ''}
            onChange={async (e) => {
              const next = Number(e.target.value)
              if (dirty && !(await notify.confirm({ title: 'Discard unsaved changes?', danger: true, okText: 'Discard' }))) return
              setUserId(next); setPending((p) => ({ ...p, [key]: {} })); setMsg(null)
            }}
            style={{ minWidth: 260, maxWidth: 360 }}
          >
            {matrix.users.map((u) => <option key={u.id} value={u.id}>{u.name} — {u.email}{u.role === 'admin' ? ' (Admin)' : ''}</option>)}
          </Select>
          {selectedUser && !isAdminUser && <span className="t-2" style={{ fontSize: 12 }}>Role: {selectedUser.roleName}</span>}
          {dirty && <Badge tone="amber" dot>Unsaved changes</Badge>}
        </div>

        {isAdminUser && (
          <div style={{ padding: '10px 18px', background: 'var(--ac-dim)', color: 'var(--ac-bright)', fontSize: 12.5, borderBottom: '1px solid var(--ac-line)' }}>
            Administrators have full access to every module by definition and cannot be modified.
          </div>
        )}
        {!canEdit && !isAdminUser && (
          <div style={{ padding: '10px 18px', background: 'var(--warn-dim)', color: 'var(--warn)', fontSize: 12.5, borderBottom: '1px solid var(--line)' }}>
            You have view-only access to this screen.
          </div>
        )}

        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead><tr>
              <th style={{ textAlign: 'left', padding: '11px 18px', fontSize: 11, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.06em', borderBottom: '1px solid var(--line)' }}>Module</th>
              {matrix.actions.map((a) => <th key={a} style={{ padding: '11px 14px', fontSize: 11, color: 'var(--tx-2)', textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid var(--line)', width: 84 }}>{a}</th>)}
              <th style={{ padding: '11px 14px', fontSize: 11, color: 'var(--tx-2)', textTransform: 'uppercase', borderBottom: '1px solid var(--line)', width: 90 }}>All</th>
              <th style={{ padding: '11px 14px', fontSize: 11, color: 'var(--tx-2)', textTransform: 'uppercase', borderBottom: '1px solid var(--line)', width: 70 }} />
            </tr></thead>
            <tbody>{matrix.modules.map((m) => {
              const rowVals = matrix.actions.map((a) => effective(m, a))
              const allOn = rowVals.every(Boolean)
              const rowOverridden = matrix.actions.some((a) => isOverridden(m, a))
              return (
                <tr key={m} className="mms-tr">
                  <td style={{ padding: '10px 18px', borderBottom: '1px solid var(--line-soft)', fontWeight: 600, color: 'var(--tx-0)' }}>{m}</td>
                  {matrix.actions.map((a) => {
                    const on = effective(m, a) || isAdminUser
                    const overridden = isOverridden(m, a)
                    const disabled = isAdminUser || !canEdit
                    return (
                      <td key={a} style={{ padding: '8px 14px', borderBottom: '1px solid var(--line-soft)', textAlign: 'center' }}>
                        <span style={{ position: 'relative', display: 'inline-block' }}>
                          <button onClick={() => toggle(m, a)} disabled={disabled} title={overridden ? 'Explicit override — differs from role default' : 'Inherited from role'} style={{ width: 34, height: 20, borderRadius: 20, border: 'none', cursor: disabled ? 'not-allowed' : 'pointer', background: on ? 'var(--ac)' : 'var(--bg-3)', position: 'relative', transition: 'background .18s', opacity: disabled ? 0.6 : 1 }}>
                            <span style={{ position: 'absolute', top: 2, left: on ? 16 : 2, width: 16, height: 16, borderRadius: '50%', background: '#fff', transition: 'left .18s' }} />
                          </button>
                          {overridden && <span title="Overridden" style={{ position: 'absolute', top: -3, right: -3, width: 7, height: 7, borderRadius: '50%', background: 'var(--warn)', border: '1px solid var(--bg-1)' }} />}
                        </span>
                      </td>
                    )
                  })}
                  <td style={{ padding: '8px 14px', borderBottom: '1px solid var(--line-soft)', textAlign: 'center' }}>
                    <Btn variant="ghost" size="sm" disabled={isAdminUser || !canEdit} onClick={() => bulkRow(m, !allOn)}>{allOn ? 'None' : 'All'}</Btn>
                  </td>
                  <td style={{ padding: '8px 14px', borderBottom: '1px solid var(--line-soft)', textAlign: 'center' }}>
                    {rowOverridden && !isAdminUser && <Btn variant="plain" size="sm" disabled={!canEdit || resetting} onClick={() => resetRow(m)} title="Reset to role default">Reset</Btn>}
                  </td>
                </tr>
              )
            })}</tbody>
          </table>
        </div>
      </Card>
    </div>
  )
}

/* ============================ Company Profile =========================== */

export function CompanyScreen({ go: _go, setBrand }: { go: Go; setBrand?: (v: { name: string, tagline: string }) => void }) {
  const [data, setData] = useState<any>({ name: 'Tazz Investment & Credit Pvt Ltd', tagline: 'Investment & Credit Management', address: '', phone: '', email: '', taxNo: '', currency: 'LKR — Sri Lankan Rupee' })
  const [accent, setAccent] = useState(document.documentElement.dataset.accent || 'blue')
  const [saving, setSaving] = useState(false)
  const [hasLogo, setHasLogo] = useState(false)
  const [logoVersion, setLogoVersion] = useState(() => Date.now())
  const [uploadingLogo, setUploadingLogo] = useState(false)
  const logoInput = useRef<HTMLInputElement>(null)
  const notify = useNotify()
  const swatches: [string, string, string][] = [['blue', 'Electric Blue', 'oklch(0.62 0.19 256)'], ['orange', 'Garage Orange', 'oklch(0.66 0.18 45)'], ['green', 'Service Green', 'oklch(0.66 0.15 158)'], ['violet', 'Performance Violet', 'oklch(0.6 0.2 290)'], ['red', 'Racing Red', 'oklch(0.6 0.21 22)']]
  const setTheme = (a: string) => { setAccent(a); document.documentElement.dataset.accent = a; localStorage.setItem('mms-accent', a) }

  useEffect(() => { companyApi.get().then((d: any) => { setData((s: any) => ({ ...s, ...d })); if (d?.name) setBrand?.({ name: d.name, tagline: d.tagline || '' }); if (d?.logoPath) setHasLogo(true) }).catch(() => {}) // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const pickLogo = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setUploadingLogo(true)
    try {
      await companyApi.uploadLogo(file)
      setHasLogo(true)
      setLogoVersion(Date.now())
      notify.success('Logo updated')
    } catch {
      notify.error('Logo upload failed')
    } finally {
      setUploadingLogo(false)
    }
  }

  const save = async () => {
    setSaving(true)
    try {
      const updated = await companyApi.update(data)
      setData((s: any) => ({ ...s, ...updated }))
      setBrand?.({ name: data.name || 'Tazz Investment & Credit Pvt Ltd', tagline: data.tagline || '' })
    } finally { setSaving(false) }
  }
  const set = (k: string, v: any) => setData((s: any) => ({ ...s, [k]: v }))

  return (
    <div>
      <PageHead crumbs="Administration" title="Company Profile" icon="building" sub="Branding, identity & system theme"
        actions={<Btn variant="primary" icon="check" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save Changes'}</Btn>} />
      <div className="comp-grid" style={{ display: 'grid', gridTemplateColumns: '1fr 340px', gap: 16, alignItems: 'start' }}>
        <div className="col gap-4">
          <Card>
            <div className="eyebrow" style={{ marginBottom: 16 }}>Company Details</div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="Company Name" full><Input value={data.name || ''} onChange={(e) => set('name', e.target.value)} /></Field>
              <Field label="Tagline" full><Input value={data.tagline || ''} onChange={(e) => set('tagline', e.target.value)} /></Field>
              <Field label="Address" full><textarea value={data.address || ''} onChange={(e) => set('address', e.target.value)} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
              <Field label="Phone"><Input value={data.phone || ''} onChange={(e) => set('phone', e.target.value)} /></Field>
              <Field label="Email"><Input value={data.email || ''} onChange={(e) => set('email', e.target.value)} /></Field>
              <Field label="Tax / VAT No"><Input value={data.taxNo || ''} onChange={(e) => set('taxNo', e.target.value)} /></Field>
              <Field label="Currency"><Select value={data.currency || ''} onChange={(e) => set('currency', e.target.value)}>{['LKR — Sri Lankan Rupee', 'PKR — Pakistani Rupee', 'INR — Indian Rupee', 'USD — US Dollar'].map((c) => <option key={c}>{c}</option>)}</Select></Field>
            </div>
          </Card>
          <Card>
            <div className="eyebrow" style={{ marginBottom: 6 }}>Logo</div>
            <div className="t-2" style={{ fontSize: 12.5, marginBottom: 16 }}>Shown on the login screen and across the app.</div>
            <div className="row gap-3" style={{ alignItems: 'center' }}>
              <div style={{ width: 56, height: 56, borderRadius: 10, background: 'var(--bg-0)', border: '1px solid var(--line)', display: 'grid', placeItems: 'center', overflow: 'hidden', flexShrink: 0 }}>
                <img src={hasLogo ? `${companyApi.logoUrl}?v=${logoVersion}` : '/logo.jpeg'} alt="Company logo" style={{ width: '100%', height: '100%', objectFit: 'contain' }} onError={() => setHasLogo(false)} />
              </div>
              <input ref={logoInput} type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" onChange={pickLogo} style={{ display: 'none' }} />
              <Btn variant="solid" icon="upload" onClick={() => logoInput.current?.click()} disabled={uploadingLogo}>{uploadingLogo ? 'Uploading…' : 'Upload Logo'}</Btn>
            </div>
          </Card>
          <Card>
            <div className="eyebrow" style={{ marginBottom: 6 }}>Color Theme</div>
            <div className="t-2" style={{ fontSize: 12.5, marginBottom: 16 }}>Pick the system accent — applies instantly.</div>
            <div className="row gap-3 wrap">
              {swatches.map(([id, label, col]) => (
                <button key={id} onClick={() => setTheme(id)} className="col gap-2 center" style={{ padding: '14px 12px', width: 120, background: accent === id ? 'var(--bg-2)' : 'var(--bg-0)', border: '1.5px solid ' + (accent === id ? 'var(--ac)' : 'var(--line)'), borderRadius: 'var(--r-m)', cursor: 'pointer', transition: 'all .15s' }}>
                  <span style={{ width: 40, height: 40, borderRadius: 10, background: col, boxShadow: accent === id ? '0 0 0 4px ' + col.replace(')', ' / 0.25)') : 'none' }} />
                  <span style={{ fontSize: 11.5, fontWeight: 600, color: accent === id ? 'var(--tx-0)' : 'var(--tx-2)' }}>{label}</span>
                  {accent === id && <Badge tone="blue">Active</Badge>}
                </button>
              ))}
            </div>
          </Card>
        </div>
        <div className="col gap-4" style={{ position: 'sticky', top: 80 }}>
          <Card>
            <div className="eyebrow" style={{ marginBottom: 14 }}>Live Preview</div>
            <div style={{ background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)', padding: 18 }}>
              <div className="row gap-3" style={{ marginBottom: 16 }}>
                <div style={{ width: 40, height: 40, borderRadius: 9, background: '#fff', display: 'grid', placeItems: 'center', overflow: 'hidden' }}>
                  <img src={hasLogo ? `${companyApi.logoUrl}?v=${logoVersion}` : '/logo.jpeg'} alt="" style={{ width: '100%', height: '100%', objectFit: 'contain' }} onError={() => setHasLogo(false)} />
                </div>
                <div><div style={{ fontFamily: 'Saira', fontWeight: 800, fontSize: 18 }}>{data.name || 'Tazz Investment & Credit Pvt Ltd'}</div><div className="eyebrow" style={{ fontSize: 9 }}>{data.tagline}</div></div>
              </div>
              <Btn variant="primary" style={{ width: '100%', marginBottom: 8 }}>Primary Button</Btn>
              <div className="row gap-2"><Badge tone="blue" dot>Accent</Badge><Badge tone="green" dot>Success</Badge><Badge tone="amber" dot>Pending</Badge></div>
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
