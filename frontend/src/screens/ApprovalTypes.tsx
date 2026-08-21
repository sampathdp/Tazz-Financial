/* Tazz Investment & Credit — Approval Types: admin-only registry of named
   "approval points" (loan_cancel, payment_void, and any future ones) and who
   may approve each — data-driven, so adding an approver (or a whole new type,
   once a future feature wires its code in) never needs a code deploy. See
   ApprovalType::isAuthorized() on the backend. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, SearchSelect, Modal, Table, Td, inputStyle, ErrorBanner , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { api, approvalTypesApi, type ApprovalType, type AuthUser } from '../api'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

interface TypeForm { name: string; code: string; description: string; requiredApprovals: number }
const blankForm = (): TypeForm => ({ name: '', code: '', description: '', requiredApprovals: 1 })

export function ApprovalTypesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<ApprovalType[]>([])
  const [users, setUsers] = useState<{ id: number; name: string }[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<ApprovalType | null>(null)
  const [form, setForm] = useState<TypeForm>(blankForm())
  const [approversFor, setApproversFor] = useState<ApprovalType | null>(null)
  const [approverIds, setApproverIds] = useState<number[]>([])
  const [approversBusy, setApproversBusy] = useState(false)

  const refresh = () => {
    setLoading(true)
    approvalTypesApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh(); api.users.active().then(setUsers).catch(() => setUsers([])) }, [])

  const openCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setModal(true) }
  const openEdit = (t: ApprovalType) => { setEditing(t); setForm({ name: t.name, code: t.code, description: t.description || '', requiredApprovals: t.requiredApprovals ?? 1 }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name.trim()) { setErr('Name is required.'); return }
    if (!editing && !/^[a-z][a-z0-9_]*$/.test(form.code)) { setErr('Code must be lowercase letters, numbers, and underscores, starting with a letter (e.g. expense_writeoff).'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await approvalTypesApi.update(editing.id, { name: form.name, description: form.description || undefined, requiredApprovals: form.requiredApprovals })
      else await approvalTypesApi.create({ code: form.code, name: form.name, description: form.description || undefined, requiredApprovals: form.requiredApprovals })
      close(); refresh()
      notify.success(editing ? 'Approval type updated' : 'Approval type created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (t: ApprovalType) => {
    const ok = await notify.confirm({ title: `Delete approval type "${t.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await approvalTypesApi.remove(t.id); refresh(); notify.success('Approval type deleted', t.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const openApprovers = (t: ApprovalType) => { setApproversFor(t); setApproverIds((t.approvers || []).map((a) => a.id)) }
  const closeApprovers = () => { setApproversFor(null); setApproverIds([]) }
  const saveApprovers = async () => {
    if (!approversFor) return
    setApproversBusy(true)
    try {
      await approvalTypesApi.updateApprovers(approversFor.id, approverIds)
      notify.success('Approvers updated', approversFor.name)
      closeApprovers(); refresh()
    } catch (ex: any) {
      notify.error('Save failed', getErrorText(ex))
    } finally { setApproversBusy(false) }
  }

  return (
    <div>
      <PageHead crumbs="Administration" title="Approval Types" icon="shield" sub={`${rows.length} approval points`}
        actions={<Btn variant="primary" icon="plus" onClick={openCreate}>New Approval Type</Btn>} />

      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Name' }, { label: 'Code' }, { label: 'Description' }, { label: 'Required', align: 'center' }, { label: 'Approvers' }, { label: '', align: 'right', w: 130 }]}
          rows={rows} empty="No approval types"
          render={(t) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{t.name}{t.isSystem && <Badge tone="blue" dot>System</Badge>}</Td>
            <Td mono>{t.code}</Td>
            <Td c="var(--tx-2)">{t.description || '—'}</Td>
            <Td align="center">{t.requiredApprovals ?? 1}</Td>
            <Td>{t.approvers?.length ? t.approvers.map((a) => a.name).join(', ') : <span className="t-2">Admins only</span>}</Td>
            <Td align="right">
              <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                <button className="mms-act" title="Manage Approvers" onClick={() => openApprovers(t)}><Icon n="users" s={15} /></button>
                <button className="mms-act" title="Edit" onClick={() => openEdit(t)}><Icon n="edit" s={15} /></button>
                {!t.isSystem && <button className="mms-act" title="Delete" onClick={() => remove(t)}><Icon n="trash" s={15} /></button>}
              </div>
            </Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={480} title={editing ? 'Edit Approval Type' : 'New Approval Type'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div className="col gap-3">
          <Field label="Name"><Input value={form.name} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} placeholder="e.g. Expense Write-off" /></Field>
          <Field label="Code">
            <Input value={form.code} disabled={!!editing} onChange={(e) => setForm((s) => ({ ...s, code: e.target.value.toLowerCase() }))} placeholder="e.g. expense_writeoff" />
            <div className="t-2" style={{ fontSize: 11, marginTop: 4 }}>{editing ? "Can't be changed after creation — it's what a screen's Approve button checks against." : 'Lowercase letters, numbers, underscores. A developer still needs to wire an actual Approve action to check this code before it does anything.'}</div>
          </Field>
          <Field label="Approvals Required">
            <Select value={form.requiredApprovals} onChange={(e) => setForm((s) => ({ ...s, requiredApprovals: Number(e.target.value) }))}>
              <option value={1}>1 person</option>
              <option value={2}>2 people</option>
            </Select>
          </Field>
          <Field label="Description"><textarea value={form.description} onChange={(e) => setForm((s) => ({ ...s, description: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>

      <Modal open={!!approversFor} onClose={closeApprovers} width={480} title={approversFor ? `Approvers — ${approversFor.name}` : ''}
        footer={<>
          <Btn variant="plain" onClick={closeApprovers}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={saveApprovers} disabled={approversBusy}>{approversBusy ? 'Saving…' : 'Save'}</Btn>
        </>}>
        <div className="t-2" style={{ fontSize: 11.5, marginBottom: 8 }}>Who may approve/reject a "{approversFor?.name}" request. Admins can always approve, regardless of this list. Leave empty to restrict this to admins only.</div>
        {!!approverIds.length && (
          <div className="row gap-1 wrap" style={{ marginBottom: 8 }}>
            {approverIds.map((id) => {
              const u = users.find((x) => x.id === id)
              return (
                <span key={id} className="row gap-1" style={{ alignItems: 'center', padding: '4px 8px', background: 'var(--bg-2)', border: '1px solid var(--line)', borderRadius: 'var(--r-s)', fontSize: 12 }}>
                  {u?.name || `#${id}`}
                  <button type="button" onClick={() => setApproverIds((ids) => ids.filter((i) => i !== id))}
                    style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--tx-2)', display: 'flex' }}><Icon n="x" s={12} /></button>
                </span>
              )
            })}
          </div>
        )}
        <SearchSelect value="" onChange={(_n, opt) => { if (opt) setApproverIds((ids) => [...ids, opt.id]) }}
          options={users.filter((u) => !approverIds.includes(u.id))} placeholder="Add an approver…" />
      </Modal>
    </div>
  )
}
