/* Tazz Investment & Credit — Subscriptions & Document Expiry Reminders
   Domains, hosting, software licenses, and legal/compliance documents that
   carry a hard expiry date. reminderDaysBefore drives when an item starts
   showing up in the NotificationBell (see api.ts's subscriptionsApi.dueSoon()
   and Notify.tsx's loadSystemAlerts()). */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, DateInput, Select, Modal, Table, Td, inputStyle, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  subscriptionsApi, branchesApi,
  type Subscription, type SubscriptionType, type Branch, type AuthUser,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const TYPES: SubscriptionType[] = ['Domain', 'Hosting', 'Software License', 'Legal Document', 'Insurance', 'Other']
const typeTone: Record<SubscriptionType, 'blue' | 'green' | 'amber' | 'neutral'> = {
  Domain: 'blue', Hosting: 'blue', 'Software License': 'green',
  'Legal Document': 'amber', Insurance: 'amber', Other: 'neutral',
}

const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')

/** Whole calendar days from today to `isoDate` (negative once past). */
const daysUntil = (isoDate: string) => {
  const today = new Date(); today.setHours(0, 0, 0, 0)
  const target = new Date(isoDate + 'T00:00:00')
  return Math.round((target.getTime() - today.getTime()) / 86400000)
}

function ExpiryBadge({ s }: { s: Subscription }) {
  if (!s.expiryDate) return <span className="t-2">—</span>
  const days = daysUntil(s.expiryDate)
  const dueSoon = days <= (s.reminderDaysBefore ?? 30)
  if (days < 0) return <Badge tone="red" dot>Expired {Math.abs(days)}d ago</Badge>
  if (dueSoon) return <Badge tone="amber" dot>Expires in {days}d</Badge>
  return <Badge tone="green" dot>{days}d left</Badge>
}

interface SubForm extends Partial<Subscription> {}
const blankForm = (): SubForm => ({ type: 'Domain', status: 'Active', currency: 'LKR', reminderDaysBefore: 30 })

export function SubscriptionsScreen({ go: _go, user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<Subscription[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<Subscription | null>(null)
  const [form, setForm] = useState<SubForm>(blankForm())
  const [typeFilter, setTypeFilter] = useState('')
  const [search, setSearch] = useState('')

  const refresh = () => {
    setLoading(true)
    subscriptionsApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(() => { refresh(); branchesApi.list().then(setBranches).catch(() => setBranches([])) }, [])

  const openCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setModal(true) }
  const openEdit = (s: Subscription) => { setEditing(s); setForm({ ...s }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name || !form.type || !form.expiryDate) { setErr('Name, type and expiry date are required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await subscriptionsApi.update(editing.id, form)
      else await subscriptionsApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Subscription updated' : 'Subscription added', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (s: Subscription) => {
    const ok = await notify.confirm({ title: `Delete "${s.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await subscriptionsApi.remove(s.id); refresh(); notify.success('Subscription deleted', s.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  const filtered = rows.filter((s) => {
    if (typeFilter && s.type !== typeFilter) return false
    const q = search.trim().toLowerCase()
    if (q && !(s.name.toLowerCase().includes(q) || (s.provider || '').toLowerCase().includes(q) || (s.referenceNo || '').toLowerCase().includes(q))) return false
    return true
  })

  return (
    <div>
      <PageHead crumbs="Subscriptions & Renewals" title="Subscriptions & Document Expiry" icon="calendar"
        sub="Domains, hosting, licenses and legal documents — with a reminder that shows up in your notifications before each one expires."
        actions={<Can user={me} module="Subscriptions" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Item</Btn></Can>} />
      <Card pad={0}>
        <div className="row gap-2" style={{ padding: '12px 16px', borderBottom: '1px solid var(--line)', alignItems: 'center' }}>
          <div style={{ position: 'relative', maxWidth: 260, flex: 1 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--tx-2)' }}><Icon n="search" s={15} /></span>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name, provider, ref…" style={{ paddingLeft: 32 }} />
          </div>
          <Select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} style={{ maxWidth: 180 }}>
            <option value="">All types</option>
            {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
          </Select>
          <span className="t-2" style={{ fontSize: 12 }}>{filtered.length} item{filtered.length === 1 ? '' : 's'}</span>
        </div>
        {loading ? <LoadingBlock /> :
        <Table cols={[
          { label: 'Name' }, { label: 'Type' }, { label: 'Provider' }, { label: 'Expiry Date' },
          { label: 'Status', align: 'center' }, { label: 'Reminder', align: 'center' }, { label: '', align: 'right', w: 100 },
        ]}
          rows={filtered}
          empty={search.trim() || typeFilter ? 'No items match your filters' : 'Nothing tracked yet'}
          render={(s) => <>
            <Td c="var(--tx-0)"><div style={{ fontWeight: 600 }}>{s.name}</div>{s.referenceNo && <div className="t-2" style={{ fontSize: 11.5 }}>{s.referenceNo}</div>}</Td>
            <Td><Badge tone={typeTone[s.type] || 'neutral'}>{s.type}</Badge></Td>
            <Td>{s.provider || '—'}</Td>
            <Td><div>{dateOnly(s.expiryDate)}</div><div style={{ marginTop: 3 }}><ExpiryBadge s={s} /></div></Td>
            <Td align="center"><Badge tone={s.status === 'Active' ? 'green' : s.status === 'Cancelled' ? 'red' : 'neutral'} dot>{s.status || 'Active'}</Badge></Td>
            <Td align="center" mono>{s.reminderDaysBefore ?? 30}d before</Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Subscriptions" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(s)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Subscriptions" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(s)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={600} title={editing ? 'Edit Item' : 'Add Subscription / Document'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Add Item')}</Btn>
        </>}>
        <ErrorBanner error={err} style={{ marginBottom: 14 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} placeholder="e.g. company.com domain" /></Field>
          <Field label="Type"><Select value={form.type || 'Domain'} onChange={(e) => setForm((s) => ({ ...s, type: e.target.value as SubscriptionType }))}>
            {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
          </Select></Field>
          <Field label="Provider / Vendor"><Input value={form.provider || ''} onChange={(e) => setForm((s) => ({ ...s, provider: e.target.value }))} placeholder="e.g. GoDaddy, AWS" /></Field>
          <Field label="Reference No."><Input value={form.referenceNo || ''} onChange={(e) => setForm((s) => ({ ...s, referenceNo: e.target.value }))} placeholder="account / policy / license no." /></Field>
          <Field label="Start Date"><DateInput value={form.startDate || ''} onChange={(v) => setForm((s) => ({ ...s, startDate: v }))} /></Field>
          <Field label="Expiry Date"><DateInput value={form.expiryDate || ''} onChange={(v) => setForm((s) => ({ ...s, expiryDate: v }))} /></Field>
          <Field label="Remind Me (days before expiry)">
            <Input type="number" min={0} value={form.reminderDaysBefore ?? 30} onChange={(e) => setForm((s) => ({ ...s, reminderDaysBefore: Number(e.target.value) }))} />
          </Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}>
            <option>Active</option><option>Inactive</option><option>Cancelled</option>
          </Select></Field>
          <Field label="Cost"><Input type="number" step="0.01" min={0} value={form.cost ?? ''} onChange={(e) => setForm((s) => ({ ...s, cost: e.target.value }))} /></Field>
          <Field label="Currency"><Input value={form.currency || 'LKR'} onChange={(e) => setForm((s) => ({ ...s, currency: e.target.value.toUpperCase() }))} /></Field>
          <Field label="Branch (optional)" full><Select value={form.branchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
            <option value="">— Company-wide —</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select></Field>
          <Field label="Notes" full><textarea value={form.notes || ''} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        </div>
      </Modal>
    </div>
  )
}
