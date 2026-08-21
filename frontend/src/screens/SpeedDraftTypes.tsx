/* Tazz Investment & Credit — Speed Draft Types (configurable rules for Loans / Mortgages / Gold Pawning / Leases) */
import { useEffect, useState } from 'react'
import { Card, PageHead, Btn, Badge, Field, Input, Select, Modal, Table, Td, ErrorBanner , LoadingBlock } from '../components/ui'
import { Icon } from '../components/Icon'
import { speedDraftTypesApi, type SpeedDraftType, type SpeedDraftCategory, type AuthUser } from '../api'
import { Can } from '../lib/permissions'
import { useNotify } from '../components/Notify'
import { getErrorText } from '../lib/apiError'
import { DEFAULT_REFERENCE_FORMAT, previewReferenceNumber } from '../lib/referenceNumber'
import type { Go } from './types'

const CATEGORY_OPTIONS: { value: SpeedDraftCategory; label: string }[] = [
  { value: 'Loan', label: 'Loan' },
  { value: 'Mortgage', label: 'Mortgage' },
  { value: 'GoldPawning', label: 'Gold Pawning' },
  { value: 'Lease', label: 'Lease' },
]
const categoryLabel = (c?: string) => CATEGORY_OPTIONS.find((o) => o.value === c)?.label || c || '—'

const blankForm = (): Partial<SpeedDraftType> => ({
  category: 'Loan',
  ratePeriod: 'monthly',
  gracePeriodDays: 5,
  requiresCollateral: false,
  status: 'Active',
  referenceFormat: '{BRANCH}-SD-{YEAR:2}-{SEQ:6}',
})

export function SpeedDraftTypesScreen({ user: me }: { go: Go; user?: AuthUser | null }) {
  const notify = useNotify()
  const [rows, setRows] = useState<SpeedDraftType[]>([])
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<SpeedDraftType | null>(null)
  const [form, setForm] = useState<Partial<SpeedDraftType>>(blankForm())
  const set = <K extends keyof SpeedDraftType>(k: K, v: SpeedDraftType[K]) => setForm((s) => ({ ...s, [k]: v }))

  const refresh = () => {
    setLoading(true)
    speedDraftTypesApi.list().then(setRows).catch(() => setRows([])).finally(() => setLoading(false))
  }
  useEffect(refresh, [])

  const openCreate = () => { setEditing(null); setForm(blankForm()); setErr(''); setModal(true) }
  const openEdit = (t: SpeedDraftType) => { setEditing(t); setForm({ ...t }); setErr(''); setModal(true) }
  const close = () => { setModal(false); setEditing(null); setErr('') }

  const save = async () => {
    if (!form.name) { setErr('Name is required.'); return }
    if (!form.code) { setErr('Code is required.'); return }
    if (!form.category) { setErr('Category is required.'); return }
    if (form.interestRatePercent == null || form.interestRatePercent === '') { setErr('Interest rate is required.'); return }
    if (form.referenceFormat && !form.referenceFormat.includes('{SEQ')) { setErr('Reference number format must include a sequence placeholder, e.g. {SEQ:6}.'); return }
    setBusy(true); setErr('')
    try {
      if (editing) await speedDraftTypesApi.update(editing.id, form)
      else await speedDraftTypesApi.create(form)
      close(); refresh()
      notify.success(editing ? 'Speed Draft Type updated' : 'Speed Draft Type created', form.name)
    } catch (ex: any) {
      setErr(getErrorText(ex, 'Save failed'))
    } finally { setBusy(false) }
  }

  const remove = async (t: SpeedDraftType) => {
    const ok = await notify.confirm({ title: `Delete "${t.name}"?`, msg: 'This action cannot be undone.', danger: true, okText: 'Delete' })
    if (!ok) return
    try { await speedDraftTypesApi.remove(t.id); refresh(); notify.success('Speed Draft Type deleted', t.name) }
    catch (ex: any) { notify.error('Delete failed', getErrorText(ex)) }
  }

  return (
    <div>
      <PageHead crumbs="Speed Drafts" title="Speed Draft Types" icon="layers"
        sub="Configure interest rate, period, and rules for each Speed Draft category — Loans, Mortgages, Gold Pawning, and Leases."
        actions={<Can user={me} module="Speed Draft Types" action="Create"><Btn variant="primary" icon="plus" onClick={openCreate}>Add Speed Draft Type</Btn></Can>} />

      <Card pad={0}>
        {loading ? <LoadingBlock /> : (
          <Table cols={[{ label: 'Name' }, { label: 'Category' }, { label: 'Rate' }, { label: 'Amount Range' }, { label: 'Period (mo)' }, { label: 'Collateral', align: 'center' }, { label: 'Status', align: 'center' }, { label: '', w: 90 }]}
            rows={rows} empty="No Speed Draft Types yet"
            render={(t) => <>
              <Td style={{ fontWeight: 600 }}>{t.name}<div className="t-2" style={{ fontSize: 11 }}>{t.code}</div></Td>
              <Td><Badge tone="blue">{categoryLabel(t.category)}</Badge></Td>
              <Td mono>{Number(t.interestRatePercent).toFixed(3)}% {t.ratePeriod === 'annual' ? 'p.a.' : t.ratePeriod === 'daily' ? 'p.d.' : 'p.m.'}</Td>
              <Td mono>{t.minAmount != null ? Number(t.minAmount).toLocaleString() : '—'}–{t.maxAmount != null ? Number(t.maxAmount).toLocaleString() : '—'}</Td>
              <Td mono>{t.minPeriodMonths ?? '—'}–{t.maxPeriodMonths ?? '—'}</Td>
              <Td align="center">{t.requiresCollateral ? <Badge tone="amber" dot>Required</Badge> : <span className="t-2">—</span>}</Td>
              <Td align="center">{t.status === 'Inactive' ? <Badge tone="red" dot>Inactive</Badge> : <Badge tone="green" dot>Active</Badge>}</Td>
              <Td align="center">
                <div className="row gap-1" style={{ justifyContent: 'flex-end' }}>
                  <Can user={me} module="Speed Draft Types" action="Edit">
                    <button className="mms-act" title="Edit" onClick={(e) => { e.stopPropagation(); openEdit(t) }}><Icon n="edit" s={14} /></button>
                  </Can>
                  <Can user={me} module="Speed Draft Types" action="Delete">
                    <button className="mms-act" title="Delete" onClick={(e) => { e.stopPropagation(); remove(t) }}><Icon n="trash" s={14} /></button>
                  </Can>
                </div>
              </Td>
            </>} />
        )}
      </Card>

      <Modal open={modal} onClose={close} width={640}
        title={editing ? 'Edit Speed Draft Type' : 'Add Speed Draft Type'}
        footer={<>
          <Btn variant="plain" onClick={close}>Cancel</Btn>
          <Btn variant="primary" icon="check" onClick={save} disabled={busy}>{busy ? 'Saving...' : (editing ? 'Save Changes' : 'Create')}</Btn>
        </>}>
        <ErrorBanner error={err} />

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginBottom: 16 }}>
          <Field label="Name"><Input value={form.name || ''} onChange={(e) => set('name', e.target.value)} /></Field>
          <Field label="Code"><Input value={form.code || ''} onChange={(e) => set('code', e.target.value.toUpperCase())} placeholder="e.g. GOLD-PAWN-01" /></Field>
        </div>

        <div style={{ padding: 14, background: 'var(--bg-0)', border: '1px solid var(--line)', borderRadius: 'var(--r-m)', marginBottom: 16 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <Field label="Category"><Select value={form.category || 'Loan'} onChange={(e) => set('category', e.target.value as any)}>
              {CATEGORY_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </Select></Field>
            <Field label="Interest Rate %"><Input type="number" step="0.001" value={form.interestRatePercent ?? ''} onChange={(e) => set('interestRatePercent', e.target.value as any)} /></Field>
            <Field label="Rate Period"><Select value={form.ratePeriod || 'monthly'} onChange={(e) => set('ratePeriod', e.target.value as any)}>
              <option value="monthly">Monthly</option><option value="annual">Annual</option><option value="daily">Daily</option>
            </Select></Field>
            <Field label="Grace Period (Days)"><Input type="number" min={0} value={form.gracePeriodDays ?? 5} onChange={(e) => set('gracePeriodDays', Number(e.target.value) as any)} /></Field>
            <Field label="Min Amount"><Input type="number" value={form.minAmount ?? ''} onChange={(e) => set('minAmount', e.target.value as any)} /></Field>
            <Field label="Max Amount"><Input type="number" value={form.maxAmount ?? ''} onChange={(e) => set('maxAmount', e.target.value as any)} /></Field>
            <Field label="Min Period (months)"><Input type="number" value={form.minPeriodMonths ?? ''} onChange={(e) => set('minPeriodMonths', Number(e.target.value) as any)} /></Field>
            <Field label="Max Period (months)"><Input type="number" value={form.maxPeriodMonths ?? ''} onChange={(e) => set('maxPeriodMonths', Number(e.target.value) as any)} /></Field>
          </div>
          <div className="t-2" style={{ fontSize: 11.5, marginTop: 8 }}>
            The customer pays only interest each month on the outstanding capital; the full capital falls due at maturity. Interest recalculates automatically whenever a partial capital payment is made.
          </div>

          <label className="row gap-2" style={{ alignItems: 'center', cursor: 'pointer', marginTop: 14 }}>
            <input type="checkbox" checked={!!form.requiresCollateral} onChange={(e) => set('requiresCollateral', e.target.checked)} />
            <span style={{ fontSize: 13 }}>Requires collateral <span className="t-2">— e.g. a pledged gold item or leased asset. Shows a Collateral tab on drafts of this type.</span></span>
          </label>

          <div style={{ marginTop: 16 }}>
            <Field label="Reference Number Format">
              <Input value={form.referenceFormat ?? ''} onChange={(e) => set('referenceFormat', e.target.value.toUpperCase() as any)} placeholder={DEFAULT_REFERENCE_FORMAT} />
            </Field>
            <div className="t-2" style={{ fontSize: 11.5, marginTop: 4 }}>
              Placeholders: <code>{'{BRANCH}'}</code> branch code · <code>{'{YEAR}'}</code> year · <code>{'{SEQ:6}'}</code> sequence (digit count adjustable). Leave blank to use the system default.
            </div>
            <div className="t-2" style={{ fontSize: 12, marginTop: 6 }}>
              Preview: <strong>{previewReferenceNumber(form.referenceFormat || '{BRANCH}-SD-{YEAR:2}-{SEQ:6}', 'HO', '')}</strong>
            </div>
          </div>
        </div>

        <Field label="Status"><Select value={form.status || 'Active'} onChange={(e) => set('status', e.target.value as any)} style={{ maxWidth: 200 }}>
          <option>Active</option><option>Inactive</option>
        </Select></Field>
      </Modal>
    </div>
  )
}

export default SpeedDraftTypesScreen
