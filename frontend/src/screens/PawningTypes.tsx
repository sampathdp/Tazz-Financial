/* Tazz Investment & Credit — Pawning Types & Rates: the scheme master
   (rates, LTV, grace/auction-notice days — see backend PawningType) plus its
   two small supporting lookups (article types, gold rate history), bundled
   as tabs on one screen since none of the three is big enough to earn its
   own nav entry — same reasoning Masters.tsx gives for Expense Categories/
   Banks living together, just expressed as tabs instead of separate pages. */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, ErrorBanner, LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import {
  pawningTypesApi, pawningArticleTypesApi, pawningGoldRatesApi, branchesApi,
  type PawningType, type PawningArticleType, type PawningGoldRate, type AuthUser, type Branch,
} from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import type { Go } from './types'

const num = (v: unknown) => Number(v ?? 0)
const money = (v: unknown) => num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dateOnly = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 10) : '—')
const todayIso = () => new Date().toISOString().slice(0, 10)

const TABS = [
  { id: 'schemes', label: 'Pawning Schemes', icon: 'layers' },
  { id: 'articles', label: 'Article Types', icon: 'tag' },
  { id: 'rates', label: 'Gold Rates', icon: 'coins' },
] as const
type TabId = typeof TABS[number]['id']

export function PawningTypesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const [tab, setTab] = useState<TabId>('schemes')
  return (
    <div>
      <PageHead crumbs="Pawning Management" title="Pawning Types & Rates" icon="layers" />
      <div className="row gap-2" style={{ borderBottom: '1px solid var(--line)', paddingBottom: 4, marginBottom: 16 }}>
        {TABS.map((t) => <Btn key={t.id} size="sm" variant="ghost" icon={t.icon} active={tab === t.id} onClick={() => setTab(t.id)}>{t.label}</Btn>)}
      </div>
      {tab === 'schemes' && <SchemesPanel me={me} />}
      {tab === 'articles' && <ArticleTypesPanel me={me} />}
      {tab === 'rates' && <GoldRatesPanel me={me} />}
    </div>
  )
}

const blankScheme = (): Partial<PawningType> => ({
  status: 'Active', sortOrder: 0, cycleDays: 30, ltvPercent: 80, gracePeriodDays: 5, auctionEligibleDays: 90, auctionNoticeDays: 30,
})

function SchemesPanel({ me }: { me?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<PawningType[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<PawningType | null>(null)
  const [form, setForm] = useState<Partial<PawningType>>(blankScheme())

  const refresh = () => { setLoading(true); pawningTypesApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false)) }
  useEffect(refresh, [])

  const openCreate = () => { setEditing(null); setForm(blankScheme()); setErr(''); setModal(true) }
  const openEdit = (r: PawningType) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name || !form.code) { setErr('Name and code are required.'); return }
    if (!form.normalRatePercent || Number(form.normalRatePercent) <= 0) { setErr('Enter the normal rate.'); return }
    if (!form.cycleDays || Number(form.cycleDays) <= 0) { setErr('Enter the cycle length in days.'); return }
    if (!form.ltvPercent || Number(form.ltvPercent) <= 0) { setErr('Enter the loan-to-value %.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await pawningTypesApi.update(editing.id, form)
      else await pawningTypesApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Pawning scheme updated' : 'Pawning scheme created', form.name)
    } catch (ex: any) { setErr(getErrorText(ex, 'Save failed')) }
    finally { setBusy(false) }
  }

  const remove = async (r: PawningType) => {
    const ok = await notify.confirm({ title: `Delete "${r.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await pawningTypesApi.remove(r.id); refresh(); notify.success('Scheme deleted', r.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <div className="row between" style={{ marginBottom: 12 }}>
        <span className="t-2" style={{ fontSize: 12.5 }}>{rows.length} {rows.length === 1 ? 'scheme' : 'schemes'}</span>
        <Can user={me} module="Pawning Types" action="Create"><Btn variant="primary" size="sm" icon="plus" onClick={openCreate}>Add Scheme</Btn></Can>
      </div>
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Scheme' }, { label: 'Cycle' }, { label: 'Normal Rate', align: 'right' }, { label: 'Special Rate', align: 'right' }, { label: 'LTV', align: 'right' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 90 }]}
          empty="No pawning schemes yet" rows={rows}
          render={(r) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{r.name}<div className="t-2" style={{ fontSize: 11 }}>{r.code}</div></Td>
            <Td>{r.cycleDays} days</Td>
            <Td mono align="right">{Number(r.normalRatePercent).toFixed(3)}%</Td>
            <Td mono align="right">{r.specialRatePercent != null ? `${Number(r.specialRatePercent).toFixed(3)}% (≤${r.specialRateDays}d)` : '—'}</Td>
            <Td mono align="right">{Number(r.ltvPercent).toFixed(2)}%</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Pawning Types" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(r)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Pawning Types" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={640} title={editing ? 'Edit Pawning Scheme' : 'Add Pawning Scheme'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
          <Field label="Name" full><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} placeholder="e.g. 30 Days Normal Scheme" /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => setForm((s) => ({ ...s, code: e.target.value }))} /></Field>
          <Field label="Cycle Length (days)"><Input type="number" value={form.cycleDays ?? ''} onChange={(e) => setForm((s) => ({ ...s, cycleDays: Number(e.target.value) }))} /></Field>
          <Field label="Normal Rate % (per cycle)"><Input type="number" step="0.001" value={form.normalRatePercent ?? ''} onChange={(e) => setForm((s) => ({ ...s, normalRatePercent: e.target.value }))} /></Field>
          <Field label="Loan-to-Value %"><Input type="number" step="0.01" value={form.ltvPercent ?? ''} onChange={(e) => setForm((s) => ({ ...s, ltvPercent: e.target.value }))} /></Field>
          <Field label="Special Rate % (optional)"><Input type="number" step="0.001" value={form.specialRatePercent ?? ''} onChange={(e) => setForm((s) => ({ ...s, specialRatePercent: e.target.value || null }))} /></Field>
          <Field label="Special Rate Window (days)"><Input type="number" value={form.specialRateDays ?? ''} onChange={(e) => setForm((s) => ({ ...s, specialRateDays: Number(e.target.value) || null }))} /></Field>
          <Field label="Grace Period (days)"><Input type="number" value={form.gracePeriodDays ?? ''} onChange={(e) => setForm((s) => ({ ...s, gracePeriodDays: Number(e.target.value) }))} /></Field>
          <Field label="Auction-Eligible After (days overdue)"><Input type="number" value={form.auctionEligibleDays ?? ''} onChange={(e) => setForm((s) => ({ ...s, auctionEligibleDays: Number(e.target.value) }))} /></Field>
          <Field label="Auction Notice Period (days)"><Input type="number" value={form.auctionNoticeDays ?? ''} onChange={(e) => setForm((s) => ({ ...s, auctionNoticeDays: Number(e.target.value) }))} /></Field>
          <Field label="Max Renewals (optional)"><Input type="number" value={form.maxRenewalCount ?? ''} onChange={(e) => setForm((s) => ({ ...s, maxRenewalCount: Number(e.target.value) || null }))} /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
        </div>
        <div className="t-2" style={{ fontSize: 11.5, marginTop: 12 }}>
          A ticket on this scheme settles within {form.specialRateDays || '—'} days of its cycle start at the special rate; after that, the normal rate applies for the rest of the {form.cycleDays || '—'}-day cycle.
        </div>
      </Modal>
    </div>
  )
}

const blankArticleType = (): Partial<PawningArticleType> => ({ status: 'Active', sortOrder: 0 })

function ArticleTypesPanel({ me }: { me?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<PawningArticleType[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<PawningArticleType | null>(null)
  const [form, setForm] = useState<Partial<PawningArticleType>>(blankArticleType())

  const refresh = () => { setLoading(true); pawningArticleTypesApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false)) }
  useEffect(refresh, [])

  const openCreate = () => { setEditing(null); setForm(blankArticleType()); setErr(''); setModal(true) }
  const openEdit = (r: PawningArticleType) => { setEditing(r); setForm({ ...r }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name || !form.code) { setErr('Name and code are required.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await pawningArticleTypesApi.update(editing.id, form)
      else await pawningArticleTypesApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Article type updated' : 'Article type created', form.name)
    } catch (ex: any) { setErr(getErrorText(ex, 'Save failed')) }
    finally { setBusy(false) }
  }

  const remove = async (r: PawningArticleType) => {
    const ok = await notify.confirm({ title: `Delete "${r.name}"?`, danger: true, okText: 'Delete' })
    if (!ok) return
    try { await pawningArticleTypesApi.remove(r.id); refresh(); notify.success('Article type deleted', r.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <div className="row between" style={{ marginBottom: 12 }}>
        <span className="t-2" style={{ fontSize: 12.5 }}>{rows.length} article {rows.length === 1 ? 'type' : 'types'}</span>
        <Can user={me} module="Pawning Types" action="Create"><Btn variant="primary" size="sm" icon="plus" onClick={openCreate}>Add Article Type</Btn></Can>
      </div>
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Name' }, { label: 'Code' }, { label: 'Status', align: 'center' }, { label: '', align: 'right', w: 90 }]}
          empty="No article types yet" rows={rows}
          render={(r) => <>
            <Td c="var(--tx-0)" style={{ fontWeight: 600 }}>{r.name}</Td>
            <Td mono>{r.code}</Td>
            <Td align="center"><Badge tone={(r.status || 'Active') === 'Active' ? 'green' : 'red'} dot>{r.status || 'Active'}</Badge></Td>
            <Td align="right"><div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
              <Can user={me} module="Pawning Types" action="Edit"><button className="mms-act" title="Edit" onClick={() => openEdit(r)}><Icon n="edit" s={15} /></button></Can>
              <Can user={me} module="Pawning Types" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can>
            </div></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={420} title={editing ? 'Edit Article Type' : 'Add Article Type'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div className="col gap-3">
          <Field label="Name"><Input value={form.name || ''} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} placeholder="e.g. Chain" /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => setForm((s) => ({ ...s, code: e.target.value.toUpperCase() }))} placeholder="e.g. CHAIN" /></Field>
          <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value as any }))}><option>Active</option><option>Inactive</option></Select></Field>
        </div>
      </Modal>
    </div>
  )
}

const blankRate = (): { effectiveDate: string; karat: string; ratePerGram: string; branchId?: number } => ({ effectiveDate: todayIso(), karat: '22', ratePerGram: '' })

function GoldRatesPanel({ me }: { me?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<PawningGoldRate[]>([])
  const [branches, setBranches] = useState<Branch[]>([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [modal, setModal] = useState(false)
  const [form, setForm] = useState(blankRate())

  const refresh = () => { setLoading(true); pawningGoldRatesApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false)) }
  useEffect(refresh, [])
  useEffect(() => { branchesApi.list().then(setBranches).catch(() => setBranches([])) }, [])

  const openCreate = () => { setForm(blankRate()); setErr(''); setModal(true) }
  const close = () => { setModal(false); setErr('') }

  const save = async () => {
    if (!form.effectiveDate || !form.karat || !form.ratePerGram) { setErr('Date, karat and rate are required.'); return }
    setBusy(true); setErr('')
    try {
      await pawningGoldRatesApi.create({ effectiveDate: form.effectiveDate, karat: form.karat, ratePerGram: form.ratePerGram, branchId: form.branchId ?? null })
      close(); refresh()
      notify.success('Gold rate added', `${form.karat}kt — ${form.ratePerGram}/g`)
    } catch (ex: any) { setErr(getErrorText(ex, 'Save failed')) }
    finally { setBusy(false) }
  }

  const remove = async (r: PawningGoldRate) => {
    const ok = await notify.confirm({ title: 'Delete this rate?', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await pawningGoldRatesApi.remove(r.id); refresh(); notify.success('Rate deleted') }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <div className="row between" style={{ marginBottom: 12 }}>
        <span className="t-2" style={{ fontSize: 12.5 }}>{rows.length} rate {rows.length === 1 ? 'entry' : 'entries'} — most recent 200</span>
        <Can user={me} module="Pawning Types" action="Create"><Btn variant="primary" size="sm" icon="plus" onClick={openCreate}>Add Rate</Btn></Can>
      </div>
      <Card pad={0}>
        {loading ? <LoadingBlock /> :
        <Table cols={[{ label: 'Date' }, { label: 'Karat', align: 'right' }, { label: 'Rate / Gram', align: 'right' }, { label: 'Branch' }, { label: '', align: 'right', w: 60 }]}
          empty="No gold rates recorded yet" rows={rows}
          render={(r) => <>
            <Td>{dateOnly(r.effectiveDate)}</Td>
            <Td mono align="right">{Number(r.karat).toFixed(2)}kt</Td>
            <Td mono align="right">{money(r.ratePerGram)}</Td>
            <Td>{r.branch?.name || <span className="t-2">Organisation-wide</span>}</Td>
            <Td align="right"><Can user={me} module="Pawning Types" action="Delete"><button className="mms-act" title="Delete" onClick={() => remove(r)}><Icon n="trash" s={15} /></button></Can></Td>
          </>} />}
      </Card>

      <Modal open={modal} onClose={close} width={420} title="Add Gold Rate"
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Add'}</Btn>
        </>}>
        <ErrorBanner error={err} />
        <div className="col gap-3">
          <Field label="Effective Date"><Input type="date" value={form.effectiveDate} onChange={(e) => setForm((s) => ({ ...s, effectiveDate: e.target.value }))} /></Field>
          <Field label="Karat"><Input type="number" step="0.01" value={form.karat} onChange={(e) => setForm((s) => ({ ...s, karat: e.target.value }))} placeholder="22" /></Field>
          <Field label="Rate per Gram"><Input type="number" step="0.01" value={form.ratePerGram} onChange={(e) => setForm((s) => ({ ...s, ratePerGram: e.target.value }))} /></Field>
          <Field label="Branch (optional — blank applies organisation-wide)">
            <Select value={form.branchId ?? ''} onChange={(e) => setForm((s) => ({ ...s, branchId: Number(e.target.value) || undefined }))}>
              <option value="">Organisation-wide</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </Select>
          </Field>
        </div>
      </Modal>
    </div>
  )
}
